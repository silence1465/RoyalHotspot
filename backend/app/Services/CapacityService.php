<?php

namespace App\Services;

use App\Jobs\ApplyUsagePolicyJob;
use App\Models\InternetPackage;
use App\Models\Purchase;
use App\Models\Router;
use App\Models\RouterIsp;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\DB;

class CapacityService
{
    private const RESERVED_STATUSES = ['verified', 'active', 'voucher_assigned', 'completed'];

    public function unavailableMessage(string $reason): string
    {
        return match ($reason) {
            'subscriber_capacity' => 'Capacity is full. Please check again next month or when another ISP becomes available.',
            'package_configuration' => 'This package is unavailable because its data allowance has not been configured.',
            default => 'Data is unavailable. Please try again next month or when more capacity is added.',
        };
    }

    public function mode(): string
    {
        $mode = (string) SystemSetting::get('operating_mode', 'normal');

        return in_array($mode, ['normal', 'data_cap', 'user_cap'], true) ? $mode : 'normal';
    }

    public function availability(Router $router, InternetPackage $package, ?int $customerId = null): array
    {
        if ($this->mode() === 'normal') {
            return ['available' => true, 'reason' => null];
        }

        $required = (int) $package->data_allowance_bytes;
        if ($required <= 0) {
            return ['available' => false, 'reason' => 'package_configuration'];
        }
        if ($this->mode() === 'user_cap' && $customerId === null) {
            return ['available' => false, 'reason' => 'subscriber_capacity'];
        }

        foreach ($this->candidateIsps($router, $customerId) as $isp) {
            if ($this->ispCanAccept($isp, $required, $customerId)) {
                return ['available' => true, 'reason' => null, 'router_isp_id' => $isp->id];
            }
        }

        return [
            'available' => false,
            'reason' => $this->mode() === 'user_cap' ? 'subscriber_capacity' : 'data_capacity',
        ];
    }

    public function reserve(Purchase $purchase): array
    {
        if ($this->mode() === 'normal') {
            return ['reserved' => true, 'reason' => null];
        }

        return DB::transaction(function () use ($purchase) {
            $locked = Purchase::with(['package', 'router'])->whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            if ($locked->capacity_month?->isSameMonth(now()) && $locked->capacity_reserved_bytes > 0) {
                return ['reserved' => true, 'reason' => null];
            }

            $required = (int) $locked->package->data_allowance_bytes;
            if ($required <= 0) {
                return ['reserved' => false, 'reason' => 'package_configuration'];
            }

            foreach ($this->candidateIsps($locked->router, $locked->customer_id) as $candidate) {
                $isp = RouterIsp::whereKey($candidate->id)->lockForUpdate()->first();
                if (! $isp || ! $this->ispCanAccept($isp, $required, $locked->customer_id, $locked->id)) {
                    continue;
                }

                $locked->update([
                    'router_isp_id' => $isp->id,
                    'capacity_month' => now()->startOfMonth()->toDateString(),
                    'capacity_reserved_bytes' => $required,
                    'queue_reason' => null,
                ]);

                return ['reserved' => true, 'reason' => null, 'router_isp_id' => $isp->id];
            }

            return [
                'reserved' => false,
                'reason' => $this->mode() === 'user_cap' ? 'subscriber_capacity' : 'data_capacity',
            ];
        }, 3);
    }

    public function summary(RouterIsp $isp): array
    {
        $reserved = $this->reservedBytes($isp);
        $customers = $this->subscriberCount($isp);
        $capacity = (int) ($isp->monthly_capacity_bytes ?? 0);

        return [
            'capacity_bytes' => $capacity,
            'reserved_bytes' => $reserved,
            'remaining_bytes' => max(0, $capacity - $reserved),
            'subscriber_limit' => $isp->subscriber_limit,
            'subscribers_allocated' => $customers,
            'subscriber_slots_remaining' => $isp->subscriber_limit === null
                ? null
                : max(0, (int) $isp->subscriber_limit - $customers),
        ];
    }

    public function carryForward(Purchase $purchase): void
    {
        DB::transaction(function () use ($purchase) {
            $locked = Purchase::whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            if (! $locked->router_isp_id || ! $locked->capacity_month
                || $locked->capacity_month->isSameMonth(now())) {
                return;
            }

            $isp = RouterIsp::whereKey($locked->router_isp_id)->lockForUpdate()->first();
            if (! $isp || ! $isp->enabled) {
                $locked->update(['data_allowance_bytes' => $locked->cycle_bytes_used]);
                ApplyUsagePolicyJob::dispatch($locked->id);

                return;
            }

            $alreadyRolled = $locked->rollover_expires_on !== null;
            $eligible = ! $alreadyRolled && $locked->starts_at && $locked->cycle_bytes_used > 0;
            $unused = $eligible
                ? max(0, (int) $locked->data_allowance_bytes - (int) $locked->cycle_bytes_used)
                : 0;
            $available = max(0, (int) $isp->monthly_capacity_bytes - $this->reservedBytes($isp, $locked->id));
            $rollover = min($unused, $available);

            $locked->update([
                'capacity_month' => now()->startOfMonth()->toDateString(),
                'capacity_reserved_bytes' => $rollover,
                'rollover_bytes' => $rollover,
                'rollover_expires_on' => now()->endOfMonth()->toDateString(),
                'data_allowance_bytes' => (int) $locked->cycle_bytes_used + $rollover,
            ]);

            ApplyUsagePolicyJob::dispatch($locked->id);
        }, 3);
    }

    private function candidateIsps(Router $router, ?int $customerId)
    {
        $stickyId = $customerId
            ? Purchase::where('customer_id', $customerId)->where('router_id', $router->id)
                ->whereNotNull('router_isp_id')->latest('id')->value('router_isp_id')
            : null;

        return $router->isps()->where('enabled', true)->get()
            ->sortBy(fn (RouterIsp $isp) => [$isp->id === $stickyId ? 0 : 1, $isp->priority, $isp->id]);
    }

    private function ispCanAccept(RouterIsp $isp, int $required, ?int $customerId, ?int $excludePurchaseId = null): bool
    {
        $capacity = (int) ($isp->monthly_capacity_bytes ?? 0);
        if ($capacity <= 0 || $this->reservedBytes($isp, $excludePurchaseId) + $required > $capacity) {
            return false;
        }

        if ($this->mode() !== 'user_cap' || $isp->subscriber_limit === null || $customerId === null) {
            return true;
        }

        $alreadyAllocated = Purchase::where('router_isp_id', $isp->id)
            ->whereDate('capacity_month', now()->startOfMonth()->toDateString())
            ->where('customer_id', $customerId)->whereIn('status', self::RESERVED_STATUSES)->exists();

        return $alreadyAllocated || $this->subscriberCount($isp) < (int) $isp->subscriber_limit;
    }

    private function reservedBytes(RouterIsp $isp, ?int $excludePurchaseId = null): int
    {
        return (int) Purchase::where('router_isp_id', $isp->id)
            ->whereDate('capacity_month', now()->startOfMonth()->toDateString())
            ->whereIn('status', self::RESERVED_STATUSES)
            ->when($excludePurchaseId, fn ($query) => $query->where('id', '!=', $excludePurchaseId))
            ->sum('capacity_reserved_bytes');
    }

    private function subscriberCount(RouterIsp $isp): int
    {
        return Purchase::where('router_isp_id', $isp->id)
            ->whereDate('capacity_month', now()->startOfMonth()->toDateString())
            ->whereIn('status', self::RESERVED_STATUSES)
            ->whereNotNull('customer_id')->distinct('customer_id')->count('customer_id');
    }
}
