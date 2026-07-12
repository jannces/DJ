<?php

namespace Tests\Feature\Rbac;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Rbac\RbacService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
    }

    public function test_super_admin_wildcard_grants_everything(): void
    {
        $user = $this->makeUser('super-admin');
        $this->assertTrue($user->hasPermission('users.manage'));
        $this->assertTrue($user->hasPermission('any.random.permission'));
    }

    public function test_role_inheritance_grants_parent_permissions(): void
    {
        // Department Head inherits Employee, so it can apply for leave too.
        $head = $this->makeUser('department-head');
        $this->assertTrue($head->hasPermission('leave.review.department'));
        $this->assertTrue($head->hasPermission('leave.apply')); // inherited from Employee
        $this->assertFalse($head->hasPermission('users.manage'));
    }

    public function test_direct_deny_overrides_role_allow(): void
    {
        $user = $this->makeUser('hr');
        $this->assertTrue($user->hasPermission('employees.manage'));

        $permission = Permission::where('slug', 'employees.manage')->first();
        app(RbacService::class)->grantUserPermission($user, $permission, 'deny');

        $this->assertFalse($user->fresh()->hasPermission('employees.manage'));
    }

    public function test_permission_middleware_blocks_unauthorized_and_logs_it(): void
    {
        $employee = $this->makeUser('employee');
        $this->actingAs($employee);
        session(['otp_verified' => true]);

        $this->get('/users')->assertForbidden();
        $this->assertDatabaseHas('intrusion_logs', ['category' => 'privilege', 'user_id' => $employee->id]);
    }

    public function test_authorized_role_reaches_protected_route(): void
    {
        $admin = $this->makeUser('system-admin');
        $this->actingAs($admin);
        session(['otp_verified' => true]);

        $this->get('/users')->assertOk();
    }

    public function test_menu_visibility_follows_permissions(): void
    {
        $employee = $this->makeUser('employee');
        $this->actingAs($employee);
        session(['otp_verified' => true]);

        $response = $this->get('/dashboard');
        $response->assertOk();
        $response->assertSee('Apply for Leave');   // employee has leave.apply
        $response->assertDontSee('Authorized Devices'); // employee lacks devices.manage
    }
}
