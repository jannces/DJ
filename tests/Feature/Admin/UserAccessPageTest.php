<?php

namespace Tests\Feature\Admin;

use App\Models\Department;
use App\Models\EmployeeProfile;
use App\Models\Permission;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Per-permission overrides, now a page of their own.
 *
 * They used to be a second form stacked under the edit form, and the two of
 * them shared a field. Both submitted the roles, so whichever button was
 * pressed second decided them — and the edit form's own role checkboxes were
 * never read by its controller at all, so the only thing that could change a
 * role was the button at the bottom of a card about something else.
 */
class UserAccessPageTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    private Position $position;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->department = Department::factory()->create();
        $this->position = Position::factory()->create();
        $this->actingAs($this->makeUser('system-admin'));
        session(['otp_verified' => true]);
    }

    private function employee(): User
    {
        $user = $this->makeUser('employee');
        EmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'department_id' => $this->department->id,
            'position_id' => $this->position->id,
        ]);

        return $user;
    }

    /** @param array<string,mixed> $overrides */
    private function payload(User $user, array $overrides = []): array
    {
        return array_merge([
            'name' => $user->name,
            'email' => $user->email,
            'roles' => [Role::where('slug', 'employee')->firstOrFail()->id],
            'first_name' => 'Juan',
            'middle_name' => 'Santos',
            'last_name' => 'Dela Cruz',
            'gender' => 'male',
            'civil_status' => 'single',
            'birth_date' => '1990-04-12',
            'contact_no' => '0917 123 4567',
            'address' => '12 Rizal St, Alicia',
            'department_id' => $this->department->id,
            'position_id' => $this->position->id,
            'employment_status' => 'permanent',
            'salary' => 25000,
            'date_hired' => '2015-06-01',
        ], $overrides);
    }

    // ------------------------------------------------- the bug that started it

    /**
     * Ticking a role on the edit form and pressing Save reported "User
     * updated." and changed nothing: update() validated the profile and never
     * read the roles the form had been showing all along.
     */
    public function test_the_edit_form_actually_saves_the_roles_it_shows(): void
    {
        $user = $this->employee();
        $head = Role::where('slug', 'department-head')->firstOrFail();

        $this->put('/users/'.$user->id, $this->payload($user, ['roles' => [$head->id]]))
            ->assertRedirect();

        $this->assertSame(['department-head'], $user->fresh()->roles->pluck('slug')->all(),
            'the role checkboxes on the edit form are decorative');
    }

    /** Granting yourself authority is not something this form can do. */
    public function test_an_administrator_cannot_change_their_own_roles(): void
    {
        $me = $this->makeUser('system-admin');
        EmployeeProfile::factory()->create([
            'user_id' => $me->id,
            'department_id' => $this->department->id,
            'position_id' => $this->position->id,
        ]);
        $this->actingAs($me);
        session(['otp_verified' => true]);

        $mayor = Role::where('slug', 'mayor')->firstOrFail();

        $this->put('/users/'.$me->id, $this->payload($me, ['roles' => [$mayor->id]]))
            ->assertRedirect();

        $this->assertSame(['system-admin'], $me->fresh()->roles->pluck('slug')->all(),
            'an administrator just granted themselves a role');
    }

    /** The profile still saves while the roles are refused. */
    public function test_editing_your_own_profile_still_works(): void
    {
        $me = $this->makeUser('system-admin');
        EmployeeProfile::factory()->create([
            'user_id' => $me->id,
            'department_id' => $this->department->id,
            'position_id' => $this->position->id,
        ]);
        $this->actingAs($me);
        session(['otp_verified' => true]);

        $this->put('/users/'.$me->id, $this->payload($me, ['name' => 'Renamed Person']))
            ->assertRedirect();

        $this->assertSame('Renamed Person', $me->fresh()->name);
    }

    // ------------------------------------------------------------- the new page

    public function test_the_overrides_are_no_longer_on_the_edit_form(): void
    {
        $user = $this->employee();

        $html = $this->get('/users/'.$user->id.'/edit')->assertOk()->getContent();

        $this->assertStringNotContainsString('name="allow[]"', $html,
            'the thirty-five override rows are still wedged into the edit form');
        $this->assertStringContainsString(route('users.access', $user), $html,
            'there is no way from the edit form to the overrides');
    }

    public function test_the_edit_form_says_how_many_overrides_are_in_effect(): void
    {
        $user = $this->employee();
        $perm = Permission::first();
        $user->directPermissions()->attach($perm->id, ['type' => 'deny']);

        $this->get('/users/'.$user->id.'/edit')->assertOk()
            ->assertSee('override in effect')
            ->assertSee('<b>1</b>', false);

        $user->directPermissions()->attach(Permission::skip(1)->first()->id, ['type' => 'allow']);

        $this->get('/users/'.$user->id.'/edit')->assertOk()
            ->assertSee('overrides in effect')
            ->assertSee('<b>2</b>', false);
    }

    public function test_the_access_page_lists_the_permissions(): void
    {
        $user = $this->employee();

        $html = $this->get('/users/'.$user->id.'/access')->assertOk()->getContent();

        $this->assertStringContainsString('Access for '.$user->name, $html);
        $this->assertStringContainsString('name="allow[]"', $html);
        $this->assertStringContainsString('perm-columns', $html, 'the list is not in two columns');
        $this->assertStringContainsString('Leave both unticked to inherit', $html,
            'the default state has no control to show it and is not explained either');
    }

    public function test_overrides_save(): void
    {
        $user = $this->employee();
        $allow = Permission::first();
        $deny = Permission::skip(1)->first();

        $this->post('/users/'.$user->id.'/access', [
            'allow' => [$allow->id],
            'deny' => [$deny->id],
        ])->assertRedirect(route('users.access', $user));

        $fresh = $user->fresh()->directPermissions;
        $this->assertSame('allow', $fresh->firstWhere('id', $allow->id)->pivot->type);
        $this->assertSame('deny', $fresh->firstWhere('id', $deny->id)->pivot->type);
    }

    /**
     * Two checkboxes give four combinations for a value with three meanings.
     * The save used to apply deny and drop the allow without saying so.
     */
    public function test_allowing_and_denying_the_same_permission_is_refused(): void
    {
        $user = $this->employee();
        $perm = Permission::first();

        $this->from('/users/'.$user->id.'/access')
            ->post('/users/'.$user->id.'/access', [
                'allow' => [$perm->id],
                'deny' => [$perm->id],
            ])
            ->assertRedirect('/users/'.$user->id.'/access')
            ->assertSessionHasErrors('allow.0');

        $this->assertCount(0, $user->fresh()->directPermissions,
            'the contradictory submission was saved anyway');
    }

    public function test_you_cannot_change_your_own_access(): void
    {
        $me = $this->makeUser('system-admin');
        $this->actingAs($me);
        session(['otp_verified' => true]);
        $perm = Permission::first();

        $this->post('/users/'.$me->id.'/access', ['allow' => [$perm->id]])
            ->assertRedirect();

        $this->assertCount(0, $me->fresh()->directPermissions);
    }

    /** One form per page is the whole point of the move. */
    public function test_each_page_carries_exactly_one_form_that_saves(): void
    {
        $user = $this->employee();

        foreach (['/users/'.$user->id.'/edit', '/users/'.$user->id.'/access'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $body = substr($html, strpos($html, '<div class="user-form">'));

            $this->assertSame(1, substr_count($body, '<form method="POST"'),
                $url.' has more than one form that saves, so they can fight over a field');
        }
    }

    /** It sits beside the pages this system already keeps per user. */
    public function test_it_is_reachable_from_the_user_row(): void
    {
        $user = $this->employee();

        $html = $this->get('/users')->assertOk()->getContent();

        $this->assertStringContainsString(route('users.access', $user), $html,
            'the access page cannot be reached from the list');
        $this->assertStringContainsString(route('users.history', $user), $html);
    }
}
