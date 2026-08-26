<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RolePermissionSeeder extends Seeder
{
    /**
     * Permission catalog: slug => [name, module]. Every check in the app
     * resolves against these DB rows — nothing is hardcoded in code paths.
     */
    private array $permissions = [
        '*' => ['Full system access (wildcard)', 'system'],
        'dashboard.view' => ['View own dashboard', 'dashboard'],

        'users.manage' => ['Create/update/archive/restore/delete users', 'users'],
        'users.block' => ['Manually block & unblock accounts', 'users'],
        'users.reset-password' => ['Reset user passwords', 'users'],
        'users.assign-roles' => ['Assign roles and permissions to users', 'users'],
        'users.history' => ['View login and audit history of users', 'users'],
        'rbac.manage' => ['Manage roles and permissions', 'rbac'],
        'settings.manage' => ['Manage system settings', 'settings'],

        'devices.manage' => ['Manage authorized devices', 'devices'],
        'security.dashboard' => ['View security dashboard & alerts', 'security'],
        'security.blocked-ips' => ['Manage blocked IP addresses', 'security'],
        'security.intrusions' => ['View intrusion logs', 'security'],
        'audit.view' => ['View audit logs', 'audit'],
        'activity.view' => ['View activity logs', 'audit'],

        'employees.view' => ['View employee records', 'employees'],
        'employees.manage' => ['Create/update/archive employees', 'employees'],
        'employees.view-salary' => ['See employee salary fields', 'employees'],
        'departments.manage' => ['Manage departments', 'organization'],
        'positions.manage' => ['Manage positions', 'organization'],
        'holidays.manage' => ['Maintain the holiday calendar', 'organization'],

        'leave.apply' => ['File leave applications', 'leave'],
        'leave.view-own' => ['View own leave requests, balances, history', 'leave'],
        'leave.cancel' => ['Cancel own pending leave requests', 'leave'],
        // Named a step that no longer exists. What it does — and all it has
        // done since the approval chain was collapsed to one step — is let its
        // holder read leave requests from their own department.
        'leave.review.department' => ["View own department's leave requests", 'leave'],
        'leave.certify.hr' => ['Validate & certify leave credits (HR step)', 'leave'],
        'leave.approve.final' => ['Final approval/disapproval (Mayor step)', 'leave'],
        'leave.requests.view-all' => ['View all leave requests', 'leave'],
        'leave.balances.manage' => ['Adjust leave balances', 'leave'],
        'leave-types.manage' => ['Configure leave types & policies', 'leave'],

        'reports.generate' => ['Generate & export operational reports', 'reports'],
        'reports.security' => ['Generate & export security reports', 'reports'],
    ];

    public function run(): void
    {
        foreach ($this->permissions as $slug => [$name, $module]) {
            Permission::updateOrCreate(['slug' => $slug], [
                'name' => $name, 'module' => $module, 'description' => $name,
            ]);
        }

        $employee = Role::updateOrCreate(['slug' => 'employee'], [
            'name' => 'Employee', 'is_system' => true,
            'description' => 'Regular LGU employee: files and tracks own leave.',
        ]);

        // Department Head inherits everything Employee can do (role inheritance).
        $deptHead = Role::updateOrCreate(['slug' => 'department-head'], [
            'name' => 'Department Head', 'is_system' => true, 'parent_id' => $employee->id,
            'description' => 'Reviews and recommends leave for own department.',
        ]);

        $hr = Role::updateOrCreate(['slug' => 'hr'], [
            'name' => 'HR', 'is_system' => true, 'parent_id' => $employee->id,
            'description' => 'Human Resources: employees, balances, certification, reports.',
        ]);

        $mayor = Role::updateOrCreate(['slug' => 'mayor'], [
            'name' => 'Municipal Mayor', 'is_system' => true, 'parent_id' => $employee->id,
            'description' => 'Final approving authority for leave applications.',
        ]);

        $sysAdmin = Role::updateOrCreate(['slug' => 'system-admin'], [
            'name' => 'System Administrator', 'is_system' => true,
            'description' => 'Operates users, devices, security monitoring and settings.',
        ]);

        $grant = function (Role $role, array $slugs): void {
            $ids = Permission::whereIn('slug', $slugs)->pluck('id');
            $role->permissions()->sync($ids);
        };

        $grant($employee, [
            'dashboard.view', 'leave.apply', 'leave.view-own', 'leave.cancel',
        ]);
        // Department Head currently holds nothing of its own — only what it
        // inherits from Employee.
        //
        // NOTE: the description on this role says it reviews and recommends
        // leave for its department, and that is what the LGU's process
        // actually requires. The code does not do it: the approval chain was
        // collapsed to a single step (see the 2026_08_14 migration), which
        // removed the Department Head stage. The description is the intent and
        // the empty grant is the implementation, and they disagree. Reinstating
        // the stage is a workflow change, not an RBAC grant — do not paper over
        // it by handing this role `leave.approve.final`, which would let a head
        // decide any office's leave, not just their own.
        $grant($deptHead, []);
        $grant($hr, [
            'employees.view', 'employees.manage', 'employees.view-salary',
            'departments.manage', 'positions.manage', 'holidays.manage',
            'leave.requests.view-all', 'leave.balances.manage', 'leave-types.manage',
            'leave.certify.hr', 'leave.approve.final', 'reports.generate',
        ]);
        // Mayor and HR are the two authorized approvers. Any ONE of them can
        // decide an application — see ApprovalWorkflowService, which gates on
        // the permission rather than on a role slug.
        $grant($mayor, ['leave.approve.final', 'leave.requests.view-all']);
        $grant($sysAdmin, [
            'dashboard.view', 'users.manage', 'users.block', 'users.reset-password',
            'users.assign-roles', 'users.history', 'rbac.manage', 'settings.manage',
            'devices.manage', 'security.dashboard', 'security.blocked-ips',
            'security.intrusions', 'audit.view', 'activity.view',
            'reports.generate', 'reports.security',
        ]);

        // No role holds `*`. Super Admin did, and the System Administrator
        // already covers what an administrator does here — so there is now no
        // permission anywhere that satisfies every check, and none that this
        // installation can grant by accident. RoleController refuses it too.

        DB::table('cache')->where('key', 'like', '%rbac%')->delete();
    }
}
