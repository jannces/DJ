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
        // The bootstrap login. It used to be a Super Admin holding `*`; that
        // role is gone and the System Administrator already covers everything
        // an administrator does here, so no account on a fresh install holds a
        // permission that satisfies every check.
        //
        // It deliberately holds no leave permission: whoever installs the
        // system administers it, and reading employees' leave records is HR's
        // job, not the installer's.
        $admin = User::updateOrCreate(['email' => 'superadmin@alicia.gov.ph'], [
            'name' => 'System Administrator',
            'username' => 'superadmin',
            'password' => Hash::make(env('SEED_SUPERADMIN_PASSWORD', 'ChangeMe!Alicia2026')),
            'status' => User::STATUS_ACTIVE,
            'must_change_password' => true,
            'email_verified_at' => now(),
        ]);
        $admin->roles()->syncWithoutDetaching(Role::where('slug', 'system-admin')->first());

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
