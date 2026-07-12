<?php

namespace Database\Seeders;

use App\Models\AuthorizedDevice;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class CoreUserSeeder extends Seeder
{
    public function run(): void
    {
        $superAdmin = User::updateOrCreate(['email' => 'superadmin@alicia.gov.ph'], [
            'name' => 'Super Administrator',
            'username' => 'superadmin',
            'password' => Hash::make(env('SEED_SUPERADMIN_PASSWORD', 'ChangeMe!Alicia2026')),
            'status' => User::STATUS_ACTIVE,
            'must_change_password' => true,
            'email_verified_at' => now(),
        ]);
        $superAdmin->roles()->syncWithoutDetaching(Role::where('slug', 'super-admin')->first());

        // The server itself is always an authorized device so admins can never
        // be locked out by device enforcement (bootstrap guarantee, ADR-006).
        foreach (['127.0.0.1', '::1'] as $ip) {
            AuthorizedDevice::updateOrCreate(['ip_address' => $ip], [
                'hostname' => 'lms-server (localhost)',
                'description' => 'Application server loopback — seeded, do not remove',
                'status' => 'active',
                'last_active_at' => now(),
            ]);
        }
    }
}
