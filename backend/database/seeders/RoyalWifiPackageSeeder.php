<?php

namespace Database\Seeders;

use App\Models\InternetPackage;
use Illuminate\Database\Seeder;

/**
 * Separate from InternetPackageSeeder (which seeds the original
 * router-subscription packages) since these are a genuinely different
 * concern — sales_channel='voucher' means these never appear in the
 * original "Buy Internet" storefront, only the Royal WiFi one. Without
 * this seeder, GET /customer/packages returns nothing purchasable via
 * Mobile Money on a
 * fresh install and there's nothing to actually test the buy flow
 * against — added during the Phase 8 test pass for exactly that reason.
 *
 * Prices/durations match the spec's own example exactly.
 */
class RoyalWifiPackageSeeder extends Seeder
{
    public function run(): void
    {
        $packages = [
            [
                'name' => 'Weekly',
                'description' => '1 week of Royal WiFi access.',
                'price' => 30.00,
                'duration_value' => 7,
                'duration_unit' => 'days',
                'sales_channel' => 'voucher',
            ],
            [
                'name' => 'Monthly',
                'description' => '1 month of Royal WiFi access.',
                'price' => 100.00,
                'duration_value' => 30,
                'duration_unit' => 'days',
                'sales_channel' => 'voucher',
            ],
        ];

        foreach ($packages as $data) {
            InternetPackage::updateOrCreate(
                ['name' => $data['name'], 'sales_channel' => 'voucher'],
                $data + ['status' => 'active']
            );
        }
    }
}
