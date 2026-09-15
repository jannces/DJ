<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\Admin\UserController;
use App\Models\Department;
use App\Models\EmployeeProfile;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Creating and editing a user account.
 *
 * Two rules this page has to hold. Only five roles can be handed out, and that
 * is checked on the submission rather than only in the picker. And every field
 * the CSC Form 6 header is built from has to be filled in — the leave form an
 * employee later files prints department, position, salary and hire date
 * straight onto the sheet, so a blank here surfaces when somebody is holding
 * the paper.
 */
class UserFormTest extends TestCase
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

    /** @param array<string,mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Juan Dela Cruz',
            'username' => 'jdelacruz',
            'email' => 'juan@alicia.gov.ph',
            'employee_no' => 'EMP-9001',
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
            'salary' => 32000,
            'date_hired' => '2018-06-01',
        ], $overrides);
    }

    public function test_a_complete_submission_creates_the_account_and_its_profile(): void
    {
        $this->post('/users', $this->payload())->assertRedirect(route('users.index'));

        $user = User::where('username', 'jdelacruz')->firstOrFail();
        $this->assertSame('single', $user->employeeProfile->civil_status);
        $this->assertSame($this->department->id, $user->employeeProfile->department_id);
        $this->assertTrue($user->roles->pluck('slug')->contains('employee'));
    }

    // ------------------------------------------------------------- the roles

    public function test_the_picker_offers_five_roles_and_no_others(): void
    {
        $html = $this->get('/users/create')->assertOk()->getContent();

        foreach (['Employee', 'Department Head', 'HR', 'Municipal Mayor', 'System Administrator'] as $name) {
            $this->assertStringContainsString($name, $html);
        }

        // Five, and five is every role there is: the LGU has no sixth, and the
        // Roles page offers no way to invent one.
        $this->assertCount(5, Role::assignable()->get());
        $this->assertSame(5, Role::count(), 'a role exists that no account can be given');
    }

    /**
     * Leaving a role out of the picker is presentation. The submission is
     * where it has to be refused, because the form can be replayed with any
     * role id in it.
     */
    public function test_a_role_id_the_form_did_not_offer_is_refused_on_submission(): void
    {
        // Every role is assignable now, so the case left to guard is a role id
        // that does not correspond to one — which is what a replayed form with
        // an edited value looks like.
        $this->post('/users', $this->payload([
            'username' => 'probe',
            'email' => 'probe@alicia.gov.ph',
            'employee_no' => 'EMP-P1',
            'roles' => [Role::max('id') + 99],
        ]))->assertSessionHasErrors('roles.0');

        $this->assertSame(0, User::where('username', 'like', 'probe%')->count());
    }

    public function test_an_account_must_be_given_at_least_one_role(): void
    {
        $this->post('/users', $this->payload(['roles' => []]))
            ->assertSessionHasErrors('roles');
    }

    /**
     * Editing an account whose role the picker cannot show must not quietly
     * demote it: the role is absent from the submission, and a plain sync
     * would drop it.
     */
    public function test_editing_does_not_strip_a_role_the_form_cannot_show(): void
    {
        // No role is unassignable today, so this is guarding the mechanism
        // rather than a live case: a role outside ASSIGNABLE must survive a
        // save from a form that could not offer it.
        $hidden = Role::create([
            'name' => 'Auditor General', 'slug' => 'auditor-general', 'is_system' => true,
        ]);
        $owner = $this->makeUser('employee');
        $owner->roles()->attach($hidden);

        $employee = Role::where('slug', 'employee')->firstOrFail();

        // Roles are saved by the edit form now, not by a second form stacked
        // under it. $owner has an employee profile from makeUser(), so a full
        // payload is what the edit form would send.
        $owner->employeeProfile()->create(
            \App\Models\EmployeeProfile::factory()->raw([
                'user_id' => $owner->id,
                'department_id' => $this->department->id,
                'position_id' => $this->position->id,
            ])
        );

        $this->put('/users/'.$owner->id, $this->payload([
            'name' => $owner->name,
            'email' => $owner->email,
            'roles' => [$employee->id],
        ]))->assertRedirect();

        $this->assertEqualsCanonicalizing(
            ['auditor-general', 'employee'],
            $owner->fresh()->roles->pluck('slug')->all(),
            'the unshowable role has to survive a save from this form'
        );
    }

    // -------------------------------------------------------- the validation

    /** @return array<string,array{string}> */
    public static function requiredFields(): array
    {
        $fields = [
            // employee_no is not here: it is issued by the server rather than
            // submitted, so there is no request value to require.
            'name', 'username', 'email', 'first_name', 'last_name',
            'gender', 'civil_status', 'birth_date', 'contact_no', 'address',
            'department_id', 'position_id', 'employment_status', 'salary', 'date_hired',
        ];

        $cases = [];
        foreach ($fields as $field) {
            $cases[$field] = [$field];
        }

        return $cases;
    }

    /** @dataProvider requiredFields */
    public function test_every_required_field_is_enforced_on_the_server(string $field): void
    {
        $this->post('/users', $this->payload([$field => null]))
            ->assertSessionHasErrors($field);

        $this->assertSame(0, User::where('username', 'jdelacruz')->count());
    }

    public function test_a_middle_name_stays_optional(): void
    {
        $this->post('/users', $this->payload(['middle_name' => null]))
            ->assertSessionHasNoErrors();
    }

    /** @return array<string,array{string,mixed}> */
    public static function badValues(): array
    {
        return [
            'civil status off the list' => ['civil_status', 'complicated'],
            'gender off the list' => ['gender', 'other'],
            'employment status off the list' => ['employment_status', 'volunteer'],
            'a birth date in the future' => ['birth_date', '2030-01-01'],
            'a child as an employee' => ['birth_date', '2020-01-01'],
            'a hire date in the future' => ['date_hired', '2099-01-01'],
            'a negative salary' => ['salary', -1],
            'letters in a phone number' => ['contact_no', 'call me'],
            'a department that does not exist' => ['department_id', 999999],
            'a position that does not exist' => ['position_id', 999999],
        ];
    }

    /** @dataProvider badValues */
    public function test_a_value_off_the_list_is_refused(string $field, mixed $value): void
    {
        $this->post('/users', $this->payload([$field => $value]))
            ->assertSessionHasErrors($field);
    }

    public function test_somebody_cannot_be_hired_before_they_were_born(): void
    {
        $this->post('/users', $this->payload([
            'birth_date' => '1990-04-12',
            'date_hired' => '1985-01-01',
        ]))->assertSessionHasErrors('date_hired');
    }

    /**
     * Gender, civil status, birth date and hire date were collected by the form
     * and then dropped by the update — they could never be corrected once the
     * account existed.
     */
    public function test_editing_can_correct_every_profile_field(): void
    {
        $this->post('/users', $this->payload())->assertRedirect();
        $user = User::where('username', 'jdelacruz')->firstOrFail();

        $this->put('/users/'.$user->id, $this->payload([
            'civil_status' => 'married',
            'gender' => 'female',
            'birth_date' => '1991-05-13',
            'date_hired' => '2019-07-02',
            'salary' => 41000,
        ]))->assertRedirect(route('users.index'));

        $profile = $user->fresh()->employeeProfile;
        $this->assertSame('married', $profile->civil_status);
        $this->assertSame('female', $profile->gender);
        $this->assertSame('1991-05-13', $profile->birth_date->toDateString());
        $this->assertSame('2019-07-02', $profile->date_hired->toDateString());
    }

    // ------------------------------------------------------------ the markup

    public function test_civil_status_is_a_dropdown_of_the_accepted_values(): void
    {
        $html = $this->get('/users/create')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<select[^>]*name="civil_status"/', $html);
        foreach (UserController::CIVIL_STATUSES as $value) {
            $this->assertStringContainsString('value="'.$value.'"', $html);
        }
    }

    public function test_the_form_marks_what_it_requires(): void
    {
        $employee = $this->makeUser('employee');
        EmployeeProfile::factory()->create([
            'user_id' => $employee->id,
            'department_id' => $this->department->id,
        ]);

        foreach (['/users/create', '/users/'.$employee->id.'/edit'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            // The browser check is a convenience on top of the server rule, but
            // a field with neither tells the user nothing until they submit.
            foreach (['gender', 'civil_status', 'department_id', 'position_id', 'date_hired'] as $field) {
                $this->assertMatchesRegularExpression(
                    '/name="'.$field.'"[^>]*required/', $html, "{$field} on {$url}"
                );
            }
        }
    }
}
