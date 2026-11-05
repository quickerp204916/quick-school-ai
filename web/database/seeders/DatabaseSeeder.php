<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Platform\Models\PlatformAdmin;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PlanSeeder::class);

        // First super admin, only when PLATFORM_ADMIN_EMAIL and PLATFORM_ADMIN_PASSWORD are set.
        // Otherwise use: php artisan platform:create-admin
        $email = env('PLATFORM_ADMIN_EMAIL');
        $password = env('PLATFORM_ADMIN_PASSWORD');

        if ($email && $password && ! PlatformAdmin::where('email', $email)->exists()) {
            PlatformAdmin::create([
                'name' => env('PLATFORM_ADMIN_NAME', 'Platform Admin'),
                'email' => $email,
                'password' => $password,
                'role' => 'super_admin',
                'is_active' => true,
            ]);
        }
    }
}
