<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reduces the role list to the five the LGU actually has:
 *
 *     Employee · Department Head · HR · Municipal Mayor · System Administrator
 *
 * Two go.
 *
 * MUNICIPAL VICE MAYOR held exactly what the Mayor holds — approve, and view
 * all requests. The approval workflow is single-step and gated on the
 * `leave.approve.final` permission rather than on any role slug, so removing
 * the role removes a holder, not a stage: Mayor and HR still decide
 * applications and nothing in ApprovalWorkflowService names a role.
 *
 * SUPER ADMIN held the `*` wildcard, and the System Administrator already
 * covers what an administrator does here. Dropping it means no account holds a
 * permission that satisfies every check, which is the stronger position — but
 * it is also why the seeded bootstrap login becomes a System Administrator (see
 * CoreUserSeeder).
 *
 * ANYBODY HOLDING EITHER ROLE IS MOVED, not stranded. Deleting a role cascades
 * its pivot rows away, so an account left with no other role would silently
 * hold nothing at all — an approver who quietly stops being able to approve is
 * worse than one who is told. Vice Mayors become Mayors and Super Admins become
 * System Administrators, which is the nearest authority in each case.
 */
return new class extends Migration
{
    /** old slug => the role its holders move to */
    private const RETIRED = [
        'vice-mayor' => 'mayor',
        'super-admin' => 'system-admin',
    ];

    public function up(): void
    {
        foreach (self::RETIRED as $from => $to) {
            $fromId = DB::table('roles')->where('slug', $from)->value('id');
            $toId = DB::table('roles')->where('slug', $to)->value('id');

            if (! $fromId) {
                continue;
            }

            if ($toId) {
                $holders = DB::table('role_user')->where('role_id', $fromId)->pluck('user_id');

                foreach ($holders as $userId) {
                    $already = DB::table('role_user')
                        ->where('role_id', $toId)->where('user_id', $userId)->exists();

                    if (! $already) {
                        DB::table('role_user')->insert([
                            'role_id' => $toId,
                            'user_id' => $userId,
                        ]);
                    }
                }
            }

            // The pivot rows go with the role; the grants go with it too.
            DB::table('role_user')->where('role_id', $fromId)->delete();
            DB::table('permission_role')->where('role_id', $fromId)->delete();

            // A role inheriting from one being removed would be orphaned by the
            // delete. Nothing does today, but the guard costs one statement.
            DB::table('roles')->where('parent_id', $fromId)->update(['parent_id' => null]);

            DB::table('roles')->where('id', $fromId)->delete();
        }

        // The label named a step that no longer exists, so anybody reading the
        // permission list was told the Department Head recommends leave. What
        // the permission actually does — and has done since the workflow was
        // collapsed — is let its holder read their own department's requests.
        DB::table('permissions')->where('slug', 'leave.review.department')->update([
            'name' => "View own department's leave requests",
            'description' => "View own department's leave requests",
        ]);

        DB::table('cache')->where('key', 'like', '%rbac%')->delete();
    }

    /**
     * Recreates the two roles with the permissions they held, but cannot know
     * which accounts used to hold them — that information is gone with the
     * pivot rows. Reassign by hand after rolling back.
     */
    public function down(): void
    {
        $now = now();
        $employeeId = DB::table('roles')->where('slug', 'employee')->value('id');

        $restore = [
            'vice-mayor' => [
                'name' => 'Municipal Vice Mayor',
                'description' => 'Authorized approving officer for leave applications.',
                'parent_id' => $employeeId,
                'permissions' => ['leave.approve.final', 'leave.requests.view-all'],
            ],
            'super-admin' => [
                'name' => 'Super Admin',
                'description' => 'Unrestricted platform owner.',
                'parent_id' => null,
                'permissions' => ['*'],
            ],
        ];

        foreach ($restore as $slug => $role) {
            if (DB::table('roles')->where('slug', $slug)->exists()) {
                continue;
            }

            $id = DB::table('roles')->insertGetId([
                'slug' => $slug,
                'name' => $role['name'],
                'description' => $role['description'],
                'parent_id' => $role['parent_id'],
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach (DB::table('permissions')->whereIn('slug', $role['permissions'])->pluck('id') as $permissionId) {
                DB::table('permission_role')->insert([
                    'role_id' => $id,
                    'permission_id' => $permissionId,
                ]);
            }
        }

        DB::table('permissions')->where('slug', 'leave.review.department')->update([
            'name' => 'Recommend leave (Department Head step)',
        ]);

        DB::table('cache')->where('key', 'like', '%rbac%')->delete();
    }
};
