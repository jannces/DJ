<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Seed roles, permissions, leave types and settings for every test. */
    protected function seedCore(): void
    {
        $this->seed([
            \Database\Seeders\RolePermissionSeeder::class,
            \Database\Seeders\LeaveTypeSeeder::class,
            \Database\Seeders\HolidaySeeder::class,
            \Database\Seeders\SystemSettingSeeder::class,
        ]);
    }

    protected function makeUser(string $roleSlug = 'employee'): \App\Models\User
    {
        $user = \App\Models\User::factory()->create();
        $role = \App\Models\Role::where('slug', $roleSlug)->firstOrFail();
        $user->roles()->attach($role);

        return $user;
    }
}
