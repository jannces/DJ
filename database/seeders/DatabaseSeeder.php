<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            LeaveTypeSeeder::class,
            HolidaySeeder::class,
            SystemSettingSeeder::class,
            // The offices and plantilla items every account has to name. These
            // used to exist only in DemoDataSeeder, so a clean install could
            // not create a single user.
            OrganizationSeeder::class,
            CoreUserSeeder::class,
        ]);
    }
}
