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
        'leave.review.department' => ['View leave applications in own department', 'leave'],
        'leave.certify.hr' => ['Validate & certify leave credits (HR step)', 'leave'],
        'leave.approve.final' => ['Approve or disapprove leave applications (HR)', 'leave'],
        'leave.requests.view-all' => ['View all leave requests', 'leave'],
        'leave.balances.manage' => ['Adjust leave balances', 'leave'],
        'leave-types.manage' => ['Configure leave types & policies', 'leave'],

        'reports.generate' => ['Generate & export operational reports', 'reports'],
        'reports.security' => ['Generate & export security reports', 'reports'],
        'reports.department' => ['Generate & export reports for own department', 'reports'],
    ];

    /**
     * What every person on the payroll can do with their own leave.
     *
     * Held by Employee, Department Head, HR and the Mayor. Not by the System
     * Administrator, whose account operates the system rather than working in
     * it -- their dashboard is the security one and carries no leave figures.
     *
     * @var array<string>
     */
    private const EMPLOYEE_BASELINE = [
        'dashboard.view', 'leave.apply', 'leave.view-own', 'leave.cancel',
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
            'description' => 'Sees leave filed in own department; approves none of it.',
        ]);

        $hr = Role::updateOrCreate(['slug' => 'hr'], [
            'name' => 'HR', 'is_system' => true, 'parent_id' => $employee->id,
            'description' => 'Human Resources: employees, balances, certification, reports.',
        ]);

        $mayor = Role::updateOrCreate(['slug' => 'mayor'], [
            'name' => 'Municipal Mayor', 'is_system' => true, 'parent_id' => $employee->id,
            'description' => 'Oversees leave across the LGU; signs the printed form as head of agency.',
        ]);

        $sysAdmin = Role::updateOrCreate(['slug' => 'system-admin'], [
            'name' => 'System Administrator', 'is_system' => true,
            'description' => 'Operates users, devices, security monitoring and settings.',
        ]);

        $grant = function (Role $role, array $slugs): void {
            $ids = Permission::whereIn('slug', $slugs)->pluck('id');
            $role->permissions()->sync($ids);
        };

        // Everybody on the payroll files leave, whatever else they do. A head
        // of office, an HR officer and the Mayor are employees first and hold
        // a duty second, so their roles carry this and then add to it.
        //
        // Without it a Department Head assigned only that role could not file
        // an application, see one, or cancel one -- which is the workflow the
        // LGU described, where the head applies and the Mayor and HR decide.
        // It only worked at all because the demo accounts happened to hold
        // Employee as well.
        $grant($employee, self::EMPLOYEE_BASELINE);
        // Department Head SEES its own office's leave and acts on none of it.
        // A head is notified the moment somebody under them files, and the
        // application goes straight to HR; the permission below is what turns
        // that notification into a page they can open — their dashboard pane,
        // their office's rankings, their department reports.
        //
        // Deliberately NOT `leave.approve.final`, and no longer any authority
        // at all. The office is read off the department record rather than
        // chosen, so this is not `leave.requests.view-all` in a smaller coat:
        // it cannot reach another office's applications.
        $grant($deptHead, [
            ...self::EMPLOYEE_BASELINE,
            'leave.review.department', 'reports.generate', 'reports.department',
        ]);
        $grant($hr, [
            ...self::EMPLOYEE_BASELINE,
            'employees.view', 'employees.manage', 'employees.view-salary',
            'departments.manage', 'positions.manage', 'holidays.manage',
            'leave.requests.view-all', 'leave.balances.manage', 'leave-types.manage',
            'leave.certify.hr', 'leave.approve.final', 'reports.generate',
        ]);
        // The Mayor OVERSEES leave; HR decides it. No `leave.approve.final`,
        // so the Leave Approvals queue is not merely hidden from the Mayor —
        // the route guard and ApprovalWorkflowService::canDecide() both refuse
        // them, which is the only version of this that means anything.
        //
        // What stays is sight of it: every application, and the reports behind
        // them. reports.generate is the right to run reports and each report
        // names the permission its subject needs, so this opens the six leave
        // ones and none of the four security ones.
        //
        // The Mayor's signature has not left the process. It is at the foot of
        // the printed CSC Form No. 6, as head of agency — which is where it
        // belongs on that form.
        $grant($mayor, [
            ...self::EMPLOYEE_BASELINE,
            'leave.requests.view-all', 'reports.generate',
        ]);
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
