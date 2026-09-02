<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

class CustomerFactory extends Factory
{
    protected $model = \App\Models\Customer::class;

    public function definition(): array
    {
        return [
            'full_name' => $this->faker->name(),
            'phone' => $this->faker->unique()->numerify('02#########'),
            'email' => $this->faker->optional()->safeEmail(),
            'username' => $this->faker->unique()->userName(),
            'password' => Hash::make('password'),
            'status' => 'inactive',
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => 'active']);
    }
}
