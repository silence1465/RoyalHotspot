<?php

namespace App\Jobs;

use App\Models\ActivityLog;
use App\Models\HotspotUser;
use App\Models\Purchase;
use App\Services\FupService;
use App\Services\MikrotikService;
use App\Services\MikrotikServiceFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched by PurchaseService::fulfillLive() so that the payment
 * confirmation handler can return a fast response without waiting on a
 * RouterOS API round-trip over WireGuard.
 *
 * On failure (router offline, command rejected, etc.) this job retries
 * with backoff. If it exhausts retries, the purchase is flagged
 * pending_activation for an admin to retry manually.
 */
class ActivateHotspotUserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 90];

    public function __construct(public int $purchaseId) {}

    public function handle(MikrotikServiceFactory $mikrotikFactory): void
    {
        $purchase = Purchase::with(['customer', 'package', 'router'])->findOrFail($this->purchaseId);

        if ($purchase->fulfillment_type !== 'live' || ! $purchase->router_id) {
            return;
        }

        // A delayed/retried queue job must never re-enable an account after
        // the minute-based expiry scheduler has already ended its purchase.
        if ($purchase->status !== 'active' || ($purchase->expires_at && $purchase->expires_at->isPast())) {
            return;
        }

        try {
            $this->provision($purchase, $mikrotikFactory);
        } catch (\Throwable $exception) {
            $this->revokePartialAccess($purchase, $mikrotikFactory);

            throw $exception;
        }
    }

    private function provision(Purchase $purchase, MikrotikServiceFactory $mikrotikFactory): void
    {
        $router = $purchase->router;
        $mikrotik = $mikrotikFactory->make($router);
        $profileName = $router->profileNameFor($purchase->package);

        if (! $profileName) {
            throw new \RuntimeException('No MikroTik profile is mapped to this package on the selected router.');
        }

        $profileResult = $mikrotik->ensureHotspotUserProfile(
            $profileName,
            $purchase->package->speed_limit,
            $router->address_pool
        );

        if (! $profileResult['success']) {
            throw new \RuntimeException('MikroTik profile provisioning failed: '.($profileResult['error'] ?? 'unknown'));
        }

        if ($purchase->isGuest()) {
            $this->activateGuest($purchase, $mikrotik, $profileName);

            return;
        }

        $hotspotUser = HotspotUser::where('customer_id', $purchase->customer_id)
            ->where('router_id', $router->id)
            ->first();

        if ($hotspotUser) {
            if ($hotspotUser->mikrotik_user_id) {
                $result = $mikrotik->enableHotspotUser($hotspotUser->mikrotik_user_id);

                if (! $result['success']) {
                    throw new \RuntimeException('MikroTik enable failed: '.($result['error'] ?? 'unknown'));
                }

                $profileResult = $mikrotik->changeUserProfile($hotspotUser->mikrotik_user_id, $profileName);
                if (! $profileResult['success']) {
                    throw new \RuntimeException('MikroTik profile update failed: '.($profileResult['error'] ?? 'unknown'));
                }

                $verifiedUserId = $this->verifiedUserId($mikrotik, $hotspotUser->username, $profileName);

                $hotspotUser->update([
                    'mikrotik_user_id' => $verifiedUserId,
                    'disabled' => false,
                    'profile' => $profileName,
                ]);
            } else {
                /*
                 * Local HotspotUser exists but we do not have its RouterOS ID.
                 *
                 * First try to recover an existing RouterOS user by username.
                 * If RouterOS no longer has the account, recreate it using the
                 * existing Laravel username/password instead of generating a new
                 * credential.
                 */
                $result = $mikrotik->enableHotspotUserByUsername($hotspotUser->username);

                if ($result['success']) {
                    $recoveredUserId = $result['data']['mikrotik_user_id'] ?? null;
                    if (! $recoveredUserId) {
                        throw new \RuntimeException('MikroTik user ID recovery returned no ID.');
                    }

                    $profileResult = $mikrotik->changeUserProfile($recoveredUserId, $profileName);
                    if (! $profileResult['success']) {
                        throw new \RuntimeException('MikroTik profile update failed: '.($profileResult['error'] ?? 'unknown'));
                    }

                    $verifiedUserId = $this->verifiedUserId($mikrotik, $hotspotUser->username, $profileName);

                    $hotspotUser->update([
                        'mikrotik_user_id' => $verifiedUserId,
                        'disabled' => false,
                        'profile' => $profileName,
                    ]);
                } else {
                    /*
                     * RouterOS does not have this user.
                     * Recreate it from the credential already stored by Laravel.
                     */
                    $password = $hotspotUser
                        ->makeVisible('password')
                        ->password;

                    $createResult = $mikrotik->createHotspotUser(
                        $hotspotUser->username,
                        $password,
                        $profileName
                    );

                    if (! $createResult['success']) {
                        throw new \RuntimeException('MikroTik user recreation failed: '.($createResult['error'] ?? 'unknown'));
                    }

                    $mikrotikUserId = $this->verifiedUserId(
                        $mikrotik,
                        $hotspotUser->username,
                        $profileName
                    );

                    $hotspotUser->update([
                        'mikrotik_user_id' => $mikrotikUserId,
                        'disabled' => false,
                        'profile' => $profileName,
                    ]);
                }
            }
        } else {
            $username = $purchase->customer->username;
            $password = bin2hex(random_bytes(5));

            $result = $mikrotik->createHotspotUser($username, $password, $profileName);

            if (! $result['success']) {
                throw new \RuntimeException('MikroTik user creation failed: '.($result['error'] ?? 'unknown'));
            }

            $mikrotikUserId = $this->verifiedUserId($mikrotik, $username, $profileName);

            HotspotUser::create([
                'customer_id' => $purchase->customer_id,
                'router_id' => $router->id,
                'mikrotik_user_id' => $mikrotikUserId,
                'username' => $username,
                'password' => $password,
                'profile' => $profileName,
                'disabled' => false,
            ]);
        }

        $configuredUser = HotspotUser::where('customer_id', $purchase->customer_id)
            ->where('router_id', $router->id)
            ->first();
        if ($configuredUser?->mikrotik_user_id && in_array($purchase->usage_policy, ['fup', 'data_cap'], true)) {
            $limit = $purchase->usage_policy === 'data_cap' ? (int) $purchase->data_allowance_bytes : null;
            $rate = app(FupService::class)->scaledRateLimit($purchase->base_speed_limit, 100);
            $policyResult = $mikrotik->configureUserUsagePolicy($configuredUser->mikrotik_user_id, $rate, $limit);
            if (! $policyResult['success']) {
                throw new \RuntimeException('MikroTik usage policy configuration failed: '.($policyResult['error'] ?? 'unknown'));
            }
            $purchase->update(['applied_speed_limit' => $rate, 'usage_policy_applied_at' => now()]);
            $configuredUser->update(['last_bytes_in' => 0, 'last_bytes_out' => 0]);
        }
    }

    /**
     * Guest fulfillment — a fresh 6-character code generated on the spot,
     * used as BOTH username and password (one field to remember). No
     * HotspotUser row: there's no customer_id to tie it to, and the code
     * itself (stored on the purchase) is the only record a guest can
     * recover later via phone-number lookup.
     */
    protected function activateGuest(Purchase $purchase, MikrotikService $mikrotik, string $profileName): void
    {
        $code = Purchase::generateGuestCode();

        $result = $mikrotik->createHotspotUser($code, $code, $profileName);

        if (! $result['success']) {
            throw new \RuntimeException('MikroTik guest user creation failed: '.($result['error'] ?? 'unknown'));
        }

        if ($purchase->usage_policy === 'data_cap') {
            $userId = $result['data']['after']['ret']
                ?? $result['data']['ret']
                ?? $result['data']['.id']
                ?? null;
            if ($userId) {
                $policyResult = $mikrotik->configureUserUsagePolicy(
                    $userId,
                    $purchase->base_speed_limit,
                    (int) $purchase->data_allowance_bytes
                );
                if (! $policyResult['success']) {
                    throw new \RuntimeException('MikroTik guest data cap failed: '.($policyResult['error'] ?? 'unknown'));
                }
            }
        }

        $purchase->update(['guest_code' => $code]);
    }

    protected function verifiedUserId(
        MikrotikService $mikrotik,
        string $username,
        string $profileName
    ): string {
        $verification = $mikrotik->verifyHotspotUser($username, $profileName);

        if (! $verification['success']) {
            throw new \RuntimeException(
                'MikroTik user verification failed: '.($verification['error'] ?? 'unknown')
            );
        }

        $userId = $verification['data']['mikrotik_user_id'] ?? null;
        if (! $userId) {
            throw new \RuntimeException('MikroTik user verification returned no user ID.');
        }

        return $userId;
    }

    public function failed(\Throwable $exception): void
    {
        $purchase = Purchase::find($this->purchaseId);

        if ($purchase) {
            $values = ['status' => 'pending_activation'];
            if ($purchase->payment_method !== 'free_trial') {
                $values['starts_at'] = null;
                $values['expires_at'] = null;
            }
            $purchase->update($values);

            ActivityLog::record(
                'purchase.activation_failed',
                "Purchase {$purchase->reference} activation failed and partial MikroTik access was revoked: {$exception->getMessage()}",
                $purchase->customer_id ? ['customer_id' => $purchase->customer_id] : []
            );
        }
    }

    private function revokePartialAccess(Purchase $purchase, MikrotikServiceFactory $mikrotikFactory): void
    {
        if (! $purchase->customer_id || ! $purchase->router) {
            return;
        }

        $hotspotUser = HotspotUser::where('customer_id', $purchase->customer_id)
            ->where('router_id', $purchase->router_id)
            ->first();
        if (! $hotspotUser) {
            return;
        }

        try {
            $mikrotikFactory->make($purchase->router)
                ->disableAndDisconnectHotspotUser($hotspotUser->username, $hotspotUser->mikrotik_user_id);
        } catch (\Throwable) {
            // Preserve the original provisioning exception for the queue.
        }

        $hotspotUser->update(['disabled' => true]);
    }
}
