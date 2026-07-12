<?php

namespace App\Services\Rbac;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Resolves dynamic, database-driven permissions with role inheritance and
 * per-user allow/deny overrides. Nothing here is hardcoded: adding a role,
 * permission or assignment in the admin UI changes behavior immediately
 * (a version key busts all cached resolutions on any RBAC write).
 */
class RbacService
{
    private const VERSION_KEY = 'rbac.version';
    private const TTL = 300;

    /** Wildcard permission slug held by Super Admin (seeded, still a DB record). */
    public const WILDCARD = '*';

    public function effectivePermissions(User $user): array
    {
        $key = sprintf('rbac.user.%d.v%d', $user->id, $this->version());

        return Cache::remember($key, self::TTL, function () use ($user) {
            $roleIds = DB::table('role_user')->where('user_id', $user->id)->pluck('role_id')->all();
            $map = $this->rolePermissionMap();

            $slugs = [];
            foreach ($roleIds as $roleId) {
                $slugs = array_merge($slugs, $map[$roleId]['permissions'] ?? []);
            }

            $direct = DB::table('permission_user')
                ->join('permissions', 'permissions.id', '=', 'permission_user.permission_id')
                ->where('permission_user.user_id', $user->id)
                ->get(['permissions.slug', 'permission_user.type']);

            foreach ($direct as $grant) {
                if ($grant->type === 'allow') {
                    $slugs[] = $grant->slug;
                }
            }
            $slugs = array_values(array_unique($slugs));

            // Deny overrides any allow, including role-derived ones.
            $denied = $direct->where('type', 'deny')->pluck('slug')->all();

            return array_values(array_diff($slugs, $denied));
        });
    }

    public function userHasPermission(User $user, string $slug): bool
    {
        $permissions = $this->effectivePermissions($user);

        return in_array(self::WILDCARD, $permissions, true)
            || in_array($slug, $permissions, true);
    }

    /** @param array<string> $slugs */
    public function userHasRole(User $user, array $slugs): bool
    {
        return $this->userRoleSlugs($user)->intersect($slugs)->isNotEmpty();
    }

    public function userRoleSlugs(User $user)
    {
        $key = sprintf('rbac.user-roles.%d.v%d', $user->id, $this->version());

        return Cache::remember($key, self::TTL, function () use ($user) {
            return DB::table('role_user')
                ->join('roles', 'roles.id', '=', 'role_user.role_id')
                ->where('role_user.user_id', $user->id)
                ->pluck('roles.slug');
        });
    }

    /**
     * role_id => ['slug' => ..., 'permissions' => [...]] with inheritance
     * resolved (a role owns its permissions plus every ancestor's).
     */
    public function rolePermissionMap(): array
    {
        $key = 'rbac.map.v'.$this->version();

        return Cache::remember($key, self::TTL, function () {
            $roles = Role::query()->get(['id', 'slug', 'parent_id'])->keyBy('id');
            $rolePerms = DB::table('permission_role')
                ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
                ->get(['permission_role.role_id', 'permissions.slug'])
                ->groupBy('role_id')
                ->map(fn ($rows) => $rows->pluck('slug')->all());

            $map = [];
            foreach ($roles as $role) {
                $slugs = $rolePerms[$role->id] ?? [];
                $seen = [$role->id];
                $parentId = $role->parent_id;
                while ($parentId && isset($roles[$parentId]) && ! in_array($parentId, $seen, true)) {
                    $seen[] = $parentId;
                    $slugs = array_merge($slugs, $rolePerms[$parentId] ?? []);
                    $parentId = $roles[$parentId]->parent_id;
                }
                $map[$role->id] = [
                    'slug' => $role->slug,
                    'permissions' => array_values(array_unique($slugs)),
                ];
            }

            return $map;
        });
    }

    /** Call after any role/permission/assignment mutation. */
    public function bumpVersion(): void
    {
        Cache::forever(self::VERSION_KEY, $this->version() + 1);
    }

    public function syncUserRoles(User $user, array $roleIds): void
    {
        $user->roles()->sync($roleIds);
        $this->bumpVersion();
    }

    public function syncRolePermissions(Role $role, array $permissionIds): void
    {
        $role->permissions()->sync($permissionIds);
        $this->bumpVersion();
    }

    public function grantUserPermission(User $user, Permission $permission, string $type = 'allow'): void
    {
        $user->directPermissions()->syncWithoutDetaching([$permission->id => ['type' => $type]]);
        $this->bumpVersion();
    }

    public function revokeUserPermission(User $user, Permission $permission): void
    {
        $user->directPermissions()->detach($permission->id);
        $this->bumpVersion();
    }

    private function version(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 1);
    }
}
