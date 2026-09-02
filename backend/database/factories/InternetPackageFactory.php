<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class InternetPackageFactory extends Factory
{
    protected $model = \App\Models\InternetPackage::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->randomElement(['1 Hour', '1 Day', '1 Week', '1 Month']),
            'price' => $this->faker->randomFloat(2, 2, 100),
            'duration_value' => 1,
            'duration_unit' => $this->faker->randomElement(['hours', 'days', 'weeks', 'months']),
            'speed_limit' => '5M/5M',
            'data_limit' => null,
            'status' => 'active',
        ];
    }
}
