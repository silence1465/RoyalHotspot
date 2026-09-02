<?php

namespace App\Jobs;

use App\Models\HotspotUser;
use App\Models\Purchase;
use App\Services\MikrotikService;
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

    public function __construct(public int $purchaseId)
    {
    }

    public function handle(): void
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

        if ($purchase->isGuest()) {
            $this->activateGuest($purchase);
            return;
        }

        $router = $purchase->router;
        $mikrotik = new MikrotikService($router);
        $profileName = $router->profileNameFor($purchase->package);

        $hotspotUser = HotspotUser::where('customer_id', $purchase->customer_id)
            ->where('router_id', $router->id)
            ->first();

        if ($hotspotUser) {
            if ($hotspotUser->mikrotik_user_id) {
                $result = $mikrotik->enableHotspotUser($hotspotUser->mikrotik_user_id);

                if (! $result['success']) {
                    throw new \RuntimeException('MikroTik enable failed: ' . ($result['error'] ?? 'unknown'));
                }

                $profileResult = $mikrotik->changeUserProfile($hotspotUser->mikrotik_user_id, $profileName);
                if (! $profileResult['success']) {
                    throw new \RuntimeException('MikroTik profile update failed: ' . ($profileResult['error'] ?? 'unknown'));
                }

                $hotspotUser->update([
                    'disabled' => false,
                    'profile' => $profileName,
                ]);
            } else {
                $result = $mikrotik->enableHotspotUserByUsername($hotspotUser->username);

                if (! $result['success']) {
                    throw new \RuntimeException('MikroTik enable failed: ' . ($result['error'] ?? 'unknown'));
                }

                $recoveredUserId = $result['data']['mikrotik_user_id'] ?? null;
                if (! $recoveredUserId) {
                    throw new \RuntimeException('MikroTik user ID recovery returned no ID.');
                }

                $profileResult = $mikrotik->changeUserProfile($recoveredUserId, $profileName);
                if (! $profileResult['success']) {
                    throw new \RuntimeException('MikroTik profile update failed: ' . ($profileResult['error'] ?? 'unknown'));
                }

                $hotspotUser->update([
                    'mikrotik_user_id' => $recoveredUserId,
                    'disabled' => false,
                    'profile' => $profileName,
                ]);
            }
        } else {
            $username = $purchase->customer->username;
            $password = bin2hex(random_bytes(5));

            $result = $mikrotik->createHotspotUser($username, $password, $profileName);

            if (! $result['success']) {
                throw new \RuntimeException('MikroTik user creation failed: ' . ($result['error'] ?? 'unknown'));
            }

            HotspotUser::create([
                'customer_id' => $purchase->customer_id,
                'router_id' => $router->id,
                'mikrotik_user_id' => $result['data']['after']['ret']
                    ?? $result['data']['ret']
                    ?? $result['data']['.id']
                    ?? null,
                'username' => $username,
                'password' => $password,
                'profile' => $profileName,
                'disabled' => false,
            ]);
        }
    }

    /**
     * Guest fulfillment — a fresh 6-character code generated on the spot,
     * used as BOTH username and password (one field to remember). No
     * HotspotUser row: there's no customer_id to tie it to, and the code
     * itself (stored on the purchase) is the only record a guest can
     * recover later via phone-number lookup.
     */
    protected function activateGuest(Purchase $purchase): void
    {
        $router = $purchase->router;
        $mikrotik = new MikrotikService($router);
        $profileName = $router->profileNameFor($purchase->package);

        $code = Purchase::generateGuestCode();

        $result = $mikrotik->createHotspotUser($code, $code, $profileName);

        if (! $result['success']) {
            throw new \RuntimeException('MikroTik guest user creation failed: ' . ($result['error'] ?? 'unknown'));
        }

        $purchase->update(['guest_code' => $code]);
    }

    public function failed(\Throwable $exception): void
    {
        $purchase = Purchase::find($this->purchaseId);

        if ($purchase) {
            $purchase->update(['status' => 'pending_activation']);
        }
    }
}
