<?php

namespace Database\Seeders;

use App\Models\InternetPackage;
use App\Models\Router;
use App\Models\RouterPackageProfile;
use Illuminate\Database\Seeder;

class InternetPackageSeeder extends Seeder
{
    public function run(): void
    {
        $packages = [
            ['name' => '1 Hour', 'price' => 2.00, 'duration_value' => 1, 'duration_unit' => 'hours', 'speed_limit' => '3M/3M'],
            ['name' => '1 Day', 'price' => 5.00, 'duration_value' => 1, 'duration_unit' => 'days', 'speed_limit' => '5M/5M'],
            ['name' => '1 Week', 'price' => 25.00, 'duration_value' => 1, 'duration_unit' => 'weeks', 'speed_limit' => '5M/5M'],
            ['name' => '1 Month', 'price' => 80.00, 'duration_value' => 1, 'duration_unit' => 'months', 'speed_limit' => '10M/10M'],
        ];

        $router = Router::first();

        foreach ($packages as $data) {
            $package = InternetPackage::updateOrCreate(
                ['name' => $data['name']],
                $data + ['data_limit' => null, 'status' => 'active']
            );

            // Wire up a default profile-name mapping on the sample router so
            // Phase 5+ code has something to resolve immediately. Real
            // deployments will need the actual RouterOS profile names
            // configured per router (see router_package_profiles table).
            if ($router) {
                RouterPackageProfile::updateOrCreate(
                    ['router_id' => $router->id, 'package_id' => $package->id],
                    ['profile_name' => str($data['name'])->slug()]
                );
            }
        }
    }
}
