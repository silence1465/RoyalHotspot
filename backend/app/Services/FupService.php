<?php

namespace App\Services;

use App\Models\BandwidthLog;
use App\Models\InternetPackage;
use App\Models\MonthlyCapacityAdjustment;
use App\Models\Purchase;
use Illuminate\Support\Facades\Cache;

class FupService
{
    public function snapshotPolicy(Purchase $purchase, InternetPackage $package): void
    {
        $purchase->update([
            'usage_policy' => $package->usage_policy,
            'fup_period' => $package->fup_period,
            'base_speed_limit' => $package->speed_limit,
            'data_allowance_bytes' => $package->data_allowance_bytes,
            'tier1_threshold_percent' => $package->tier1_threshold_percent,
            'tier2_threshold_percent' => $package->tier2_threshold_percent,
            'tier1_speed_percent' => $package->tier1_speed_percent,
            'tier2_speed_percent' => $package->tier2_speed_percent,
            'tier3_speed_percent' => $package->tier3_speed_percent,
            'cycle_bytes_used' => 0,
            'current_fup_tier' => 1,
            'applied_speed_limit' => null,
            'usage_policy_applied_at' => null,
            'policy_access_status' => 'active',
        ]);
    }

    public function evaluate(Purchase $purchase, int $dailyBytes): array
    {
        $allowance = (int) $purchase->data_allowance_bytes;
        $used = $purchase->usage_policy === 'fup' && $purchase->fup_period === 'daily'
            ? $dailyBytes
            : (int) $purchase->cycle_bytes_used;
        $percent = $allowance > 0 ? ($used / $allowance) * 100 : 0;

        if ($purchase->usage_policy === 'data_cap') {
            return [
                'tier' => 1,
                'usage_percent' => $percent,
                'speed_percent' => 100,
                'exhausted' => $allowance > 0 && $used >= $allowance,
            ];
        }

        $tier = $percent < $purchase->tier1_threshold_percent
            ? 1
            : ($percent < $purchase->tier2_threshold_percent ? 2 : 3);
        $tierSpeed = (int) $purchase->{'tier'.$tier.'_speed_percent'};
        $network = $this->monthlyControl();

        return [
            'tier' => $tier,
            'usage_percent' => $percent,
            'speed_percent' => max(1, (int) round($tierSpeed * $network['speed_multiplier_percent'] / 100)),
            'exhausted' => false,
            'network_control' => $network['level'],
        ];
    }

    public function monthlyControl(): array
    {
        $now = now();
        $adjustment = MonthlyCapacityAdjustment::currentForMonth($now);
        if (! $adjustment || $adjustment->capacity_bytes <= 0) {
            return ['level' => 'unconfigured', 'speed_multiplier_percent' => 100];
        }

        $cacheKey = "fup-control:{$adjustment->id}:{$now->format('YmdHi')}";
        return Cache::remember($cacheKey, 70, function () use ($now, $adjustment) {
            $used = (int) BandwidthLog::whereBetween('date', [
                $now->copy()->startOfMonth()->toDateString(),
                $now->copy()->endOfMonth()->toDateString(),
            ])->selectRaw('COALESCE(SUM(bytes_in + bytes_out), 0) as total')->value('total');
            $usable = (int) floor($adjustment->capacity_bytes * (100 - $adjustment->reserve_percent) / 100);
            $expected = $usable * $now->day / $now->daysInMonth;
            $pace = $expected > 0 ? $used / $expected : 0;

            return match (true) {
                $pace > 1.20 => ['level' => 'critical', 'speed_multiplier_percent' => 50],
                $pace > 1.10 => ['level' => 'red', 'speed_multiplier_percent' => 75],
                $pace > 1.00 => ['level' => 'amber', 'speed_multiplier_percent' => 90],
                default => ['level' => 'green', 'speed_multiplier_percent' => 100],
            };
        });
    }

    public function scaledRateLimit(?string $rateLimit, int $percent): ?string
    {
        if (! $rateLimit) {
            return null;
        }

        $parts = explode('/', $rateLimit);
        if (count($parts) !== 2) {
            return $rateLimit;
        }

        return implode('/', array_map(function (string $part) use ($percent) {
            $part = trim($part);
            if (! preg_match('/^(\d+(?:\.\d+)?)([kKmMgG]?)$/', $part, $matches)) {
                return $part;
            }
            $value = max(1, round((float) $matches[1] * $percent / 100, 2));
            return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.').$matches[2];
        }, $parts));
    }
}
