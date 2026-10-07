<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The department head is told, not asked — and HR alone decides.
 *
 * Before: Employee → DEPARTMENT REVIEW (the head recommends, and the request
 *         sits at `dept_review` until they act) → decided by any one of Mayor
 *         or HR.
 *
 * After:  Employee → the head is NOTIFIED, with nothing to act on → HR
 *         validates and decides.
 *
 * A data migration is unavoidable because roles, permissions and every
 * in-flight request are database rows rather than code. Three things move.
 *
 * 1. THE MAYOR STOPS DECIDING. `leave.approve.final` is withdrawn, which is
 *    what actually removes the authority: the workflow, the route guard and
 *    the sidebar all read that permission and none of them names a role. The
 *    Mayor keeps `leave.requests.view-all` and `reports.generate` — sight of
 *    every application and the figures behind them — and keeps their signature
 *    at the foot of the printed CSC form as head of agency.
 *
 * 2. IN-FLIGHT REQUESTS ARE FREED. Anything sitting at `dept_review` is
 *    waiting for a recommendation that can no longer be given, so it would sit
 *    there for ever. Each one moves to `pending`, and its open department row
 *    is closed as `notified` rather than deleted: an employee who was told
 *    their head had it should not find that step silently gone.
 *
 * 3. DEPARTMENT HEADS KEEP SIGHT, LOSE AUTHORITY. `leave.review.department`
 *    is NOT withdrawn — it now grants visibility of the head's own office
 *    (their dashboard pane, their office's rankings, their department
 *    reports), which is exactly what a head who is notified needs in order to
 *    follow up. Only its name changes, because the old one said "recommend".
 *
 * Decided applications are not touched. History stays as it happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // --- 1. Withdraw the decision from the Mayor -------------------------
        $approve = DB::table('permissions')->where('slug', 'leave.approve.final')->value('id');
        $mayor = DB::table('roles')->where('slug', 'mayor')->value('id');

        if ($approve && $mayor) {
            DB::table('permission_role')
                ->where('role_id', $mayor)->where('permission_id', $approve)->delete();
        }

        // Direct per-user grants of the same permission are left alone on
        // purpose: those were made by an administrator about one named person,
        // and a migration that quietly revoked them would undo a decision it
        // knows nothing about. The Users page can withdraw them one by one.

        // --- 2. Rename the two permissions that now mean something else ------
        DB::table('permissions')->where('slug', 'leave.review.department')->update([
            'name' => 'View leave applications in own department',
            'description' => 'View leave applications in own department',
            'updated_at' => $now,
        ]);
        DB::table('permissions')->where('slug', 'leave.approve.final')->update([
            'name' => 'Approve or disapprove leave applications (HR)',
            'description' => 'Approve or disapprove leave applications (HR)',
            'updated_at' => $now,
        ]);

        DB::table('roles')->where('slug', 'department-head')->update([
            'description' => 'Sees leave filed in own department; approves none of it.',
            'updated_at' => $now,
        ]);
        DB::table('roles')->where('slug', 'mayor')->update([
            'description' => 'Oversees leave across the LGU; signs the printed form as head of agency.',
            'updated_at' => $now,
        ]);

        // --- 3. Free the requests stranded at department review ---------------
        $stranded = DB::table('leave_requests')->where('status', 'dept_review')->pluck('id');

        if ($stranded->isNotEmpty()) {
            // Close the open department rows as a notification. The head's name
            // is snapshotted into `signature` so box 7.B of the printed form
            // still names who was informed even after the office changes hands.
            $heads = DB::table('leave_requests')
                ->join('employee_profiles', 'employee_profiles.user_id', '=', 'leave_requests.user_id')
                ->join('departments', 'departments.id', '=', 'employee_profiles.department_id')
                ->join('users', 'users.id', '=', 'departments.head_user_id')
                ->whereIn('leave_requests.id', $stranded)
                ->pluck('users.name', 'leave_requests.id');

            foreach ($stranded as $id) {
                DB::table('approvals')
                    ->where('leave_request_id', $id)
                    ->where('step_no', 0)
                    ->where('action', 'pending')
                    ->update([
                        'action' => 'notified',
                        'signature' => $heads[$id] ?? null,
                        'comments' => 'Recorded as a notification: the department step became informational.',
                        'acted_at' => $now,
                        'updated_at' => $now,
                    ]);
            }

            DB::table('leave_requests')->whereIn('id', $stranded)->update([
                'status' => 'pending',
                'current_step' => 1,
                'updated_at' => $now,
            ]);
        }

        // A permission change that outlives its cache is a permission change
        // that has not happened yet.
        DB::table('cache')->where('key', 'like', '%rbac%')->delete();
    }

    public function down(): void
    {
        $now = now();

        // Hand the decision back to the Mayor. The requests are NOT pushed back
        // to `dept_review`: they would land on a step whose code has gone, and
        // a rollback that strands live applications is worse than one that
        // leaves them in HR's queue, where they are still decidable.
        $approve = DB::table('permissions')->where('slug', 'leave.approve.final')->value('id');
        $mayor = DB::table('roles')->where('slug', 'mayor')->value('id');

        if ($approve && $mayor) {
            $held = DB::table('permission_role')
                ->where('role_id', $mayor)->where('permission_id', $approve)->exists();

            if (! $held) {
                DB::table('permission_role')->insert([
                    'role_id' => $mayor, 'permission_id' => $approve,
                ]);
            }
        }

        DB::table('permissions')->where('slug', 'leave.review.department')->update([
            'name' => 'Recommend leave for own department',
            'description' => 'Recommend leave for own department',
            'updated_at' => $now,
        ]);
        DB::table('permissions')->where('slug', 'leave.approve.final')->update([
            'name' => 'Final approval/disapproval (Mayor step)',
            'description' => 'Final approval/disapproval (Mayor step)',
            'updated_at' => $now,
        ]);

        DB::table('cache')->where('key', 'like', '%rbac%')->delete();
    }
};
