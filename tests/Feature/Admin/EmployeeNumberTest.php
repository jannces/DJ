<?php

namespace Tests\Feature\Admin;

use App\Models\Department;
use App\Models\EmployeeProfile;
use App\Models\Position;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The employee number fills itself in.
 *
 * Adding an account used to begin somewhere else: open the employee list, sort
 * it, read the last number off the bottom, add one. That is how two people end
 * up sharing a number, and it is work the system already had the answer to.
 *
 * A suggestion, not an allocation — the field stays editable, because an office
 * that numbers its own way has to be able to, and whatever is submitted is
 * still checked for uniqueness on the server.
 */
class EmployeeNumberTest extends TestCase
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

    private function profile(string $number): EmployeeProfile
    {
        return EmployeeProfile::factory()->create([
            'user_id' => $this->makeUser('employee')->id,
            'employee_no' => $number,
            'department_id' => $this->department->id,
            'position_id' => $this->position->id,
        ]);
    }

    // --------------------------------------------------------- the sequence

    public function test_the_first_account_starts_the_sequence(): void
    {
        $this->assertSame('EMP-0001', EmployeeProfile::nextEmployeeNo());
    }

    public function test_it_continues_from_the_highest_in_use(): void
    {
        $this->profile('EMP-0001');
        $this->profile('EMP-0007');

        $this->assertSame('EMP-0008', EmployeeProfile::nextEmployeeNo(),
            'it counted the records instead of reading the highest number');
    }

    /**
     * Compared as numbers, not as text. 'EMP-9' sorts after 'EMP-10' in a
     * string comparison, which would hand out a number already in use.
     */
    public function test_it_compares_numbers_rather_than_text(): void
    {
        $this->profile('EMP-9');
        $this->profile('EMP-10');

        $this->assertSame('EMP-0011', EmployeeProfile::nextEmployeeNo());
    }

    /**
     * An office that carried its own format across from paper keeps it. Those
     * numbers cannot collide with a generated one, since a collision could
     * only come from a number already in this shape — and those are counted.
     */
    public function test_numbers_in_another_format_are_left_out_of_it(): void
    {
        $this->profile('2019-0142');
        $this->profile('PLANTILLA/88');

        $this->assertSame('EMP-0001', EmployeeProfile::nextEmployeeNo());
    }

    // ------------------------------------------------------------- the form

    public function test_the_form_arrives_with_the_number_filled_in(): void
    {
        $this->profile('EMP-0003');

        $html = $this->get('/users/create')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<input id="f-empno"[^>]*value="EMP-0004"#s', $html,
            'the administrator still has to go and look the number up');
        $this->assertStringContainsString('Change it if your office numbers differently', $html);
    }

    /** Filled in, not fixed: it is a suggestion. */
    public function test_the_field_can_still_be_typed_over(): void
    {
        $html = $this->get('/users/create')->assertOk()->getContent();

        preg_match('#<input id="f-empno"[^>]*>#s', $html, $m);

        $this->assertStringNotContainsString('readonly', $m[0]);
        $this->assertStringNotContainsString('disabled', $m[0]);
    }

    public function test_a_number_typed_by_hand_is_the_one_used(): void
    {
        $this->post('/users', $this->payload(['employee_no' => '2026-0099']))
            ->assertRedirect();

        $this->assertDatabaseHas('employee_profiles', ['employee_no' => '2026-0099']);
    }

    public function test_the_suggested_number_saves(): void
    {
        $this->profile('EMP-0004');

        $this->post('/users', $this->payload(['employee_no' => EmployeeProfile::nextEmployeeNo()]))
            ->assertRedirect();

        $this->assertDatabaseHas('employee_profiles', ['employee_no' => 'EMP-0005']);
    }

    /**
     * Two administrators adding accounts at the same time are offered the same
     * number. Refusing it is right; only refusing it sends them back to the
     * list they were spared, so the message names one that is free.
     */
    public function test_a_clash_is_refused_and_a_free_number_named(): void
    {
        $this->profile('EMP-0001');

        $this->from('/users/create')
            ->post('/users', $this->payload(['employee_no' => 'EMP-0001']))
            ->assertSessionHasErrors(['employee_no' => 'That employee number is already taken. EMP-0002 is free.']);
    }

    /** @param array<string,mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Juan Dela Cruz',
            'username' => 'jdelacruz',
            'email' => 'juan@alicia.gov.ph',
            'employee_no' => 'EMP-0001',
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
}
