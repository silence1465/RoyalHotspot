<?php

namespace Tests\Feature;

use App\Models\Purchase;
use App\Services\FupService;
use App\Support\DataLimit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FupServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_three_fup_tiers_continue_beyond_the_allowance(): void
    {
        $service = app(FupService::class);
        $purchase = new Purchase([
            'usage_policy' => 'fup', 'fup_period' => 'cycle', 'data_allowance_bytes' => 1000,
            'tier1_threshold_percent' => 60, 'tier2_threshold_percent' => 85,
            'tier1_speed_percent' => 100, 'tier2_speed_percent' => 70, 'tier3_speed_percent' => 30,
            'cycle_bytes_used' => 600,
        ]);

        $this->assertSame(2, $service->evaluate($purchase, 0)['tier']);
        $purchase->cycle_bytes_used = 850;
        $this->assertSame(3, $service->evaluate($purchase, 0)['tier']);
        $purchase->cycle_bytes_used = 1500;
        $this->assertFalse($service->evaluate($purchase, 0)['exhausted']);
    }

    public function test_daily_fup_uses_daily_total_and_hard_cap_exhausts(): void
    {
        $service = app(FupService::class);
        $purchase = new Purchase([
            'usage_policy' => 'fup', 'fup_period' => 'daily', 'data_allowance_bytes' => 1000,
            'tier1_threshold_percent' => 60, 'tier2_threshold_percent' => 85,
            'tier1_speed_percent' => 100, 'tier2_speed_percent' => 70, 'tier3_speed_percent' => 30,
            'cycle_bytes_used' => 9999,
        ]);

        $this->assertSame(1, $service->evaluate($purchase, 500)['tier']);
        $purchase->usage_policy = 'data_cap';
        $this->assertTrue($service->evaluate($purchase, 0)['exhausted']);
    }

    public function test_rate_limit_scales_upload_and_download(): void
    {
        $this->assertSame('14M/3.5M', app(FupService::class)->scaledRateLimit('20M/5M', 70));
    }

    public function test_data_limit_notation_uses_binary_megabytes_and_gigabytes(): void
    {
        $this->assertSame(10 * 1024 * 1024, DataLimit::toBytes('10M'));
        $this->assertSame(100 * 1024 * 1024, DataLimit::toBytes('100 MB'));
        $this->assertSame(2 * 1024 * 1024 * 1024, DataLimit::toBytes('2G'));
        $this->assertSame('10M', DataLimit::notation(10, 'MB'));
    }

    public function test_hard_cap_stays_active_below_allowance_and_unlimited_policy_never_exhausts(): void
    {
        $service = app(FupService::class);
        $purchase = new Purchase([
            'usage_policy' => 'data_cap',
            'data_allowance_bytes' => 10 * 1024 * 1024,
            'cycle_bytes_used' => (10 * 1024 * 1024) - 1,
        ]);
        $this->assertFalse($service->evaluate($purchase, 0)['exhausted']);

        $purchase->usage_policy = 'none';
        $purchase->data_allowance_bytes = null;
        $purchase->cycle_bytes_used = PHP_INT_MAX;
        $this->assertFalse($service->evaluate($purchase, 0)['exhausted']);
    }
}
