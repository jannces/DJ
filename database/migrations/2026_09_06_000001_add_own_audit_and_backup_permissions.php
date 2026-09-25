<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two new permissions, onto a database that is already running.
 *
 * RolePermissionSeeder is the source of truth for these and has been updated
 * too, but re-running it is not the way to deliver them: `$grant` calls
 * `sync()`, so a seeder run replaces every role's permission set wholesale and
 * would silently discard anything an administrator had changed through the
 * RBAC screens. This adds the two rows and the grants that go with them and
 * touches nothing else.
 *
 *   audit.view-own  every audited role reads its own trail
 *   backup.run      the System Administrator takes and downloads backups
 */
return new class extends Migration
{
    /** @var array<string, array{name: string, module: string, roles: list<string>}> */
    private const ADDING = [
        'audit.view-own' => [
            'name' => 'View own audit trail',
            'module' => 'audit',
            // Not system-admin: they hold audit.view, which is the whole log
            // including their own rows, so a second entry would be a second
            // link to a page they already have.
            'roles' => ['employee', 'department-head', 'hr', 'mayor'],
        ],
        'backup.run' => [
            'name' => 'Create and download system backups',
            'module' => 'settings',
            'roles' => ['system-admin'],
        ],
    ];

    public function up(): void
    {
        foreach (self::ADDING as $slug => $spec) {
            $id = DB::table('permissions')->where('slug', $slug)->value('id');

            if (! $id) {
                $id = DB::table('permissions')->insertGetId([
                    'slug' => $slug,
                    'name' => $spec['name'],
                    'module' => $spec['module'],
                    'description' => $spec['name'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            foreach ($spec['roles'] as $roleSlug) {
                $roleId = DB::table('roles')->where('slug', $roleSlug)->value('id');

                // A role that does not exist on this installation is skipped
                // rather than created. Inventing one here would give it a name
                // and no permissions and put it in front of somebody.
                if (! $roleId) {
                    continue;
                }

                $already = DB::table('permission_role')
                    ->where('role_id', $roleId)->where('permission_id', $id)->exists();

                if (! $already) {
                    DB::table('permission_role')->insert([
                        'role_id' => $roleId,
                        'permission_id' => $id,
                    ]);
                }
            }
        }

        $this->forgetRbacCache();
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('slug', array_keys(self::ADDING))->pluck('id');

        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        $this->forgetRbacCache();
    }

    /**
     * Permission checks are cached, so without this the grants above take
     * effect whenever the cache happens to expire rather than now.
     */
    private function forgetRbacCache(): void
    {
        if (Schema::hasTable('cache')) {
            DB::table('cache')->where('key', 'like', '%rbac%')->delete();
        }
    }
};
