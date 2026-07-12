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
        'leave.review.department' => ['Recommend leave (Department Head step)', 'leave'],
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

        $superAdmin = Role::updateOrCreate(['slug' => 'super-admin'], [
            'name' => 'Super Admin', 'is_system' => true,
            'description' => 'Unrestricted platform owner.',
        ]);

        $grant = function (Role $role, array $slugs): void {
            $ids = Permission::whereIn('slug', $slugs)->pluck('id');
            $role->permissions()->sync($ids);
        };

        $grant($employee, [
            'dashboard.view', 'leave.apply', 'leave.view-own', 'leave.cancel',
        ]);
        $grant($deptHead, ['leave.review.department']); // + inherited employee perms
        $grant($hr, [
            'employees.view', 'employees.manage', 'employees.view-salary',
            'departments.manage', 'positions.manage', 'holidays.manage',
            'leave.requests.view-all', 'leave.balances.manage', 'leave-types.manage',
            'leave.certify.hr', 'reports.generate',
        ]);
        $grant($mayor, ['leave.approve.final', 'leave.requests.view-all']);
        $grant($sysAdmin, [
            'dashboard.view', 'users.manage', 'users.block', 'users.reset-password',
            'users.assign-roles', 'users.history', 'rbac.manage', 'settings.manage',
            'devices.manage', 'security.dashboard', 'security.blocked-ips',
            'security.intrusions', 'audit.view', 'activity.view',
            'reports.generate', 'reports.security',
        ]);
        $grant($superAdmin, ['*']);

        DB::table('cache')->where('key', 'like', '%rbac%')->delete();
    }
}
