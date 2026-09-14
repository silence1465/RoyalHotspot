<?php

namespace Tests\Feature;

use App\Models\Purchase;
use App\Services\FupService;
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
}
