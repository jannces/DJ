<?php

namespace Tests\Feature\Admin;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
    }

    private function actAsAdmin(): User
    {
        $admin = $this->makeUser('super-admin');
        $this->actingAs($admin);
        session(['otp_verified' => true]);

        return $admin;
    }

    /** @return array<string, mixed> */
    private function profilePayload(User $user, array $overrides = []): array
    {
        return array_merge([
            'name' => $user->name,
            'email' => $user->email,
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'salary' => 25000,
            'employment_status' => 'permanent',
        ], $overrides);
    }

    public function test_saving_the_user_form_applies_role_changes(): void
    {
        $this->actAsAdmin();
        $target = $this->makeUser('employee');
        $hrRole = Role::where('slug', 'hr')->firstOrFail();

        $this->put("/users/{$target->id}", $this->profilePayload($target, [
            'roles' => [(string) $hrRole->id],
        ]))->assertRedirect(route('users.index'));

        $this->assertSame(['hr'], $target->fresh()->roles->pluck('slug')->all());
        $this->assertTrue($target->fresh()->hasPermission('employees.manage'));
    }

    public function test_permission_overrides_are_saved_and_take_effect_immediately(): void
    {
        $this->actAsAdmin();
        $target = $this->makeUser('employee');
        $allow = Permission::where('slug', 'reports.generate')->firstOrFail();
        $deny = Permission::where('slug', 'leave.apply')->firstOrFail();

        $this->assertFalse($target->hasPermission('reports.generate'));
        $this->assertTrue($target->hasPermission('leave.apply'));

        $this->post("/users/{$target->id}/assign-roles", [
            'allow' => [(string) $allow->id],
            'deny' => [(string) $deny->id],
        ])->assertSessionHasNoErrors();

        $target = $target->fresh();
        $this->assertTrue($target->hasPermission('reports.generate'));
        $this->assertFalse($target->hasPermission('leave.apply'));
    }

    public function test_blank_checkbox_entries_do_not_reject_the_form(): void
    {
        $this->actAsAdmin();
        $target = $this->makeUser('employee');
        $allow = Permission::where('slug', 'reports.generate')->firstOrFail();

        // A blank placeholder entry used to fail "exists" and abort the save.
        $this->post("/users/{$target->id}/assign-roles", [
            'roles' => ['', (string) $target->roles->first()->id],
            'allow' => ['', (string) $allow->id],
        ])->assertSessionHasNoErrors();

        $this->assertSame(['reports.generate'], $target->fresh()->directPermissions->pluck('slug')->all());
        $this->assertSame(['employee'], $target->fresh()->roles->pluck('slug')->all());
    }

    public function test_unticking_every_override_clears_them(): void
    {
        $this->actAsAdmin();
        $target = $this->makeUser('employee');
        $allow = Permission::where('slug', 'reports.generate')->firstOrFail();

        $this->post("/users/{$target->id}/assign-roles", ['allow' => [(string) $allow->id]]);
        $this->assertCount(1, $target->fresh()->directPermissions);

        $this->post("/users/{$target->id}/assign-roles", []);
        $this->assertCount(0, $target->fresh()->directPermissions);
        $this->assertTrue($target->fresh()->hasPermission('leave.apply')); // role permissions survive
    }

    public function test_an_admin_cannot_change_their_own_roles(): void
    {
        $admin = $this->actAsAdmin();
        $employeeRole = Role::where('slug', 'employee')->firstOrFail();

        $this->put("/users/{$admin->id}", $this->profilePayload($admin, [
            'roles' => [(string) $employeeRole->id],
        ]))->assertSessionHas('error');

        $this->assertSame(['super-admin'], $admin->fresh()->roles->pluck('slug')->all());
    }

    public function test_an_admin_can_still_edit_their_own_profile(): void
    {
        $admin = $this->actAsAdmin();

        $this->put("/users/{$admin->id}", $this->profilePayload($admin, [
            'name' => 'Renamed Admin',
            'roles' => [(string) $admin->roles->first()->id],
        ]))->assertRedirect(route('users.index'));

        $this->assertSame('Renamed Admin', $admin->fresh()->name);
    }
}
