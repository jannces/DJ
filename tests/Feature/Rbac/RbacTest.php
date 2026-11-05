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

    /**
     * The wildcard still works as a mechanism — but nothing holds it, and
     * nothing can be given it.
     *
     * Super Admin was retired: the System Administrator already covers what an
     * administrator does here, so no account on this installation holds a
     * permission that satisfies every check. The mechanism is kept because
     * `hasPermission` implements it; what changed is that it is unreachable.
     */
    public function test_the_wildcard_still_resolves_but_no_role_holds_it(): void
    {
        $this->assertSame(0, Role::whereHas('permissions', fn ($q) => $q->where('slug', '*'))->count(),
            'a role holds the wildcard; one tick on the roles form grants the whole system');

        // Granted directly, out of band, it still resolves — the mechanism is
        // intact, which is what makes hiding it from the form meaningful.
        $user = $this->makeUser('employee');
        $user->directPermissions()->attach(Permission::where('slug', '*')->firstOrFail(), ['type' => 'allow']);
        $user->refresh();

        $this->assertTrue($user->hasPermission('any.random.permission'));
    }

    public function test_the_wildcard_is_not_offered_on_the_roles_form(): void
    {
        $admin = $this->makeUser('system-admin');
        $this->actingAs($admin);
        session(['otp_verified' => true]);

        $role = Role::where('slug', 'department-head')->firstOrFail();
        $wildcard = Permission::where('slug', '*')->firstOrFail();

        $html = $this->get('/roles/'.$role->id.'/edit')->assertOk()->getContent();
        $this->assertStringNotContainsString('value="'.$wildcard->id.'"', $html,
            'the wildcard is a checkbox again; one click grants the whole system');

        // And refused on the way in, because hiding a control is not access
        // control and this form can be replayed with any permission id.
        $this->put('/roles/'.$role->id, [
            'name' => $role->name,
            'permissions' => [$wildcard->id],
        ])->assertSessionHasErrors('permissions.0');

        $this->assertFalse($role->fresh()->permissions->contains('slug', '*'));
    }

    public function test_there_is_no_way_to_create_or_delete_a_role(): void
    {
        $this->actingAs($this->makeUser('system-admin'));
        session(['otp_verified' => true]);

        // Neither route exists any more, so both paths answer "method not
        // allowed": the only verbs left on /roles are GET (the list) and, on
        // /roles/{role}, PUT and DELETE.
        $this->get('/roles/create')->assertStatus(405);
        $this->post('/roles', ['name' => 'Invented', 'slug' => 'invented'])->assertStatus(405);

        $this->assertSame(5, \App\Models\Role::count(), 'a sixth role was created');

        $this->assertStringNotContainsString('New role',
            $this->get('/roles')->assertOk()->getContent());

        // Delete is still routed, and still refuses every one of the five.
        $role = Role::where('slug', 'hr')->firstOrFail();
        $this->delete('/roles/'.$role->id);
        $this->assertDatabaseHas('roles', ['slug' => 'hr']);
    }

    /** The LGU has five roles and the application has exactly those five. */
    public function test_the_five_fixed_roles_are_the_whole_list(): void
    {
        $this->assertEqualsCanonicalizing(
            ['employee', 'department-head', 'hr', 'mayor', 'system-admin'],
            Role::pluck('slug')->all()
        );
    }

    public function test_role_inheritance_grants_parent_permissions(): void
    {
        // Department Head inherits Employee, so it can apply for leave itself.
        // It no longer holds any leave-approval authority (single-step workflow).
        $head = $this->makeUser('department-head');
        $this->assertTrue($head->hasPermission('leave.apply')); // inherited from Employee
        $this->assertTrue($head->hasPermission('leave.view-own')); // inherited
        $this->assertFalse($head->hasPermission('leave.approve.final'));
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
