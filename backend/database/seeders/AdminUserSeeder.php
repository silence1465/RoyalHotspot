<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        // Password is read from env so a default credential never ships
        // in source control / a public repo. Falls back to a random
        // string (printed once) if not set, rather than a fixed default
        // like "password123" that would sit in every deployment.
        $password = env('DEFAULT_ADMIN_PASSWORD');

        if ($password && strlen($password) < 16) {
            throw new \RuntimeException('DEFAULT_ADMIN_PASSWORD must contain at least 16 characters.');
        }

        if (! $password) {
            $password = str()->random(16);
            $this->command?->warn("DEFAULT_ADMIN_PASSWORD not set in .env — generated one-time password: {$password}");
            $this->command?->warn('Log in and change it immediately, or set DEFAULT_ADMIN_PASSWORD before re-seeding.');
        }

        User::firstOrCreate(
            ['email' => env('DEFAULT_ADMIN_EMAIL', 'admin@hotspotbilling.test')],
            [
                'name' => 'Super Admin',
                'phone' => null,
                'password' => Hash::make($password),
                'role' => 'super_admin',
                'status' => 'active',
            ]
        );
    }
}
