<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class RouterFactory extends Factory
{
    protected $model = \App\Models\Router::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company() . ' Hotspot',
            'location' => $this->faker->city(),
            'router_ip' => $this->faker->localIpv4(),
            'wireguard_ip' => '10.10.' . $this->faker->numberBetween(0, 255) . '.' . $this->faker->numberBetween(2, 254),
            'api_username' => 'api-admin',
            // encrypted cast handles this transparently on save
            'api_password' => $this->faker->password(12, 20),
            'api_port' => 8729,
            'api_ssl' => true,
            'status' => 'offline',
        ];
    }
}
