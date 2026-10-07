<?php
namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PermissionMigrationTest extends TestCase
{
    use RefreshDatabase;

    /** The migration adds the grants an existing installation is missing. */
    public function test_the_migration_grants_are_idempotent(): void
    {
        $this->seedCore();

        $mig = require base_path('database/migrations/2026_09_06_000001_add_own_audit_and_backup_permissions.php');

        // Simulate an installation from before these existed.
        $ids = DB::table('permissions')->whereIn('slug', ['audit.view-own', 'backup.run'])->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        $mig->up();
        $first = DB::table('permission_role')->count();

        // Running it twice must not duplicate a single row.
        $mig->up();
        $this->assertSame($first, DB::table('permission_role')->count());

        foreach (['employee', 'department-head', 'hr', 'mayor'] as $slug) {
            $this->assertTrue($this->roleHas($slug, 'audit.view-own'), "{$slug} did not get audit.view-own");
            $this->assertFalse($this->roleHas($slug, 'backup.run'), "{$slug} was given backup.run");
        }

        $this->assertTrue($this->roleHas('system-admin', 'backup.run'));
    }

    private function roleHas(string $roleSlug, string $permSlug): bool
    {
        return DB::table('permission_role')
            ->join('roles', 'roles.id', '=', 'permission_role.role_id')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('roles.slug', $roleSlug)->where('permissions.slug', $permSlug)->exists();
    }
}
