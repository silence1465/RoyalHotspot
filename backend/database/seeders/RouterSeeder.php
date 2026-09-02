<?php

namespace Database\Seeders;

use App\Models\Router;
use Illuminate\Database\Seeder;

class RouterSeeder extends Seeder
{
    public function run(): void
    {
        Router::updateOrCreate(
            ['name' => 'Main Office Hotspot'],
            [
                'location' => 'Accra HQ',
                'router_ip' => '192.168.88.1',
                'wireguard_ip' => env('SAMPLE_ROUTER_WIREGUARD_IP', '10.10.0.2'),
                'api_username' => env('SAMPLE_ROUTER_API_USER', 'api-admin'),
                // Never commit a real router password to a seeder in
                // source control — this pulls from env with an obviously
                // fake placeholder fallback for local dev only.
                'api_password' => env('SAMPLE_ROUTER_API_PASSWORD', 'change-me-in-env'),
                'api_port' => 8729,
                'api_ssl' => true,
                'status' => 'offline',
            ]
        );
    }
}
