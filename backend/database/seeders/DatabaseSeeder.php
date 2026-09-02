<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            AdminUserSeeder::class,
            RouterSeeder::class,
            InternetPackageSeeder::class,
            RoyalWifiPackageSeeder::class,
            RoyalWifiVoucherSeeder::class,
        ]);
    }
}
