<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Collapses the leave approval chain to a single step.
 *
 * Before: Employee → Department Head → HR (certify) → Mayor (final).
 * After:  Employee → Pending → ANY ONE of Mayor, Vice Mayor or HR.
 *
 * A data migration is required because roles, permissions and each leave type's
 * approval_flow are database records, not code. Existing installations already
 * carry the three-step flow and in-flight requests sitting on its middle steps,
 * so those are migrated too rather than left stranded.
 *
 * The Department Head role is NOT deleted — it may carry other meaning in the
 * organisation. Only its authority over leave approval is withdrawn.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // 1. Vice Mayor role — a new approver alongside Mayor and HR.
        $employeeRoleId = DB::table('roles')->where('slug', 'employee')->value('id');
        if (! DB::table('roles')->where('slug', 'vice-mayor')->exists()) {
            DB::table('roles')->insert([
                'slug' => 'vice-mayor',
                'name' => 'Municipal Vice Mayor',
                'description' => 'Authorized approving officer for leave applications.',
                'parent_id' => $employeeRoleId,
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // 2. `leave.approve.final` is now the single approval authority.
        $approve = DB::table('permissions')->where('slug', 'leave.approve.final')->value('id');
        $viewAll = DB::table('permissions')->where('slug', 'leave.requests.view-all')->value('id');

        foreach (['mayor', 'vice-mayor', 'hr'] as $slug) {
            $roleId = DB::table('roles')->where('slug', $slug)->value('id');
            if (! $roleId) {
                continue;
            }
            foreach (array_filter([$approve, $viewAll]) as $permissionId) {
                $exists = DB::table('permission_role')
                    ->where('role_id', $roleId)->where('permission_id', $permissionId)->exists();
                if (! $exists) {
                    DB::table('permission_role')->insert([
                        'role_id' => $roleId,
                        'permission_id' => $permissionId,
                    ]);
                }
            }
        }

        // 3. Withdraw leave-approval authority from Department Head. The role and
        //    its other permissions stay; only the review step is taken away.
        $reviewPermission = DB::table('permissions')->where('slug', 'leave.review.department')->value('id');
        if ($reviewPermission) {
            DB::table('permission_role')->where('permission_id', $reviewPermission)->delete();
            DB::table('permission_user')->where('permission_id', $reviewPermission)->delete();
        }

        // 4. Every leave type now runs the one-step flow.
        DB::table('leave_types')->update(['approval_flow' => json_encode(['authorized'])]);

        // 5. Migrate in-flight requests. Anything still moving through the old
        //    chain returns to a single pending decision; decided requests are
        //    left exactly as they are so history stays truthful.
        $openStatuses = ['dept_review', 'hr_review', 'final_review'];
        $openIds = DB::table('leave_requests')->whereIn('status', $openStatuses)->pluck('id');

        if ($openIds->isNotEmpty()) {
            // Drop the undecided steps and leave one pending "authorized" step.
            DB::table('approvals')
                ->whereIn('leave_request_id', $openIds)
                ->where('action', 'pending')
                ->delete();

            foreach ($openIds as $id) {
                $nextStep = (int) DB::table('approvals')->where('leave_request_id', $id)->max('step_no');
                $nextStep = DB::table('approvals')->where('leave_request_id', $id)->exists() ? $nextStep + 1 : 0;

                DB::table('approvals')->insert([
                    'leave_request_id' => $id,
                    'step_no' => $nextStep,
                    'role_slug' => 'authorized',
                    'action' => 'pending',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('leave_requests')->where('id', $id)->update([
                    'status' => 'pending',
                    'current_step' => $nextStep,
                    'updated_at' => $now,
                ]);
            }
        }

        // Cached RBAC resolutions must not survive a permission change.
        DB::table('cache')->where('key', 'like', '%rbac%')->delete();
    }

    public function down(): void
    {
        // Restore the three-step flow on the leave types. Roles are left as they
        // are: re-granting Department Head approval automatically could hand back
        // authority an administrator has since removed on purpose.
        $standard = json_encode(['department_head', 'hr', 'mayor']);
        $hrMayor = json_encode(['hr', 'mayor']);

        DB::table('leave_types')->whereIn('code', ['VL', 'FL', 'SL', 'PL', 'SPL', 'SOLO'])
            ->update(['approval_flow' => $standard]);
        DB::table('leave_types')->whereNotIn('code', ['VL', 'FL', 'SL', 'PL', 'SPL', 'SOLO'])
            ->update(['approval_flow' => $hrMayor]);

        DB::table('cache')->where('key', 'like', '%rbac%')->delete();
    }
};
