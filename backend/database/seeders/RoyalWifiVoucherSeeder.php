<?php

namespace Database\Seeders;

use App\Models\InternetPackage;
use App\Models\Voucher;
use Illuminate\Database\Seeder;

/**
 * Test/demo data only — a handful of 'available' vouchers so the buy
 * flow (Extension Phase 4/6) can actually be exercised end-to-end
 * without needing a real MikroTik-generated PDF first. Uses the exact
 * example codes from the spec's own sample PDF. Replace with real
 * PDF-imported vouchers before going live — this seeder's only purpose
 * is making the Phase 8 test pass actually runnable.
 */
class RoyalWifiVoucherSeeder extends Seeder
{
    public function run(): void
    {
        $weekly = InternetPackage::where('name', 'Weekly')->where('sales_channel', 'voucher')->first();
        $monthly = InternetPackage::where('name', 'Monthly')->where('sales_channel', 'voucher')->first();

        if (! $weekly || ! $monthly) {
            $this->command?->warn('RoyalWifiPackageSeeder must run before RoyalWifiVoucherSeeder — skipping.');
            return;
        }

        $codes = [
            [$weekly, 'RW30-82KD-91PL'],
            [$weekly, 'RW30-92KD-72LM'],
            [$weekly, 'RW30-PL82-92KD'],
            [$monthly, 'RW100-72KD-91PL'],
            [$monthly, 'RW100-82LM-72PQ'],
        ];

        foreach ($codes as [$package, $code]) {
            Voucher::updateOrCreate(
                ['code' => $code],
                [
                    'package_id' => $package->id,
                    'status' => 'available',
                    'amount' => $package->price,
                    'duration_days' => $package->duration_value,
                ]
            );
        }
    }
}
