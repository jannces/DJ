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
 * It is issued rather than suggested: the field is read-only and the server
 * ignores whatever the request carries, because readonly is a hint to the
 * browser and nothing more. That is what makes the number permanent — assigned
 * once, never edited, and never reissued.
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
        $this->assertStringContainsString('Never reused, even after an account is archived', $html);
    }

    /** Shown, not asked for: the number is the system's to issue. */
    public function test_the_field_cannot_be_typed_over(): void
    {
        $html = $this->get('/users/create')->assertOk()->getContent();

        preg_match('#<input id="f-empno"[^>]*>#s', $html, $m);

        $this->assertStringContainsString('readonly', $m[0]);
        $this->assertStringNotContainsString('name="employee_no"', $m[0],
            'the form still sends a number the server could be talked into using');
    }

    /**
     * readonly is a hint to the browser and nothing more, so the rule is on
     * the server: whatever arrives in the request is discarded.
     */
    public function test_a_number_sent_in_the_request_is_ignored(): void
    {
        $this->profile('EMP-0003');

        $this->post('/users', $this->payload(['employee_no' => 'EMP-0001']))
            ->assertRedirect();

        $this->assertDatabaseHas('employee_profiles', ['employee_no' => 'EMP-0004']);
        $this->assertSame(0, EmployeeProfile::where('employee_no', 'EMP-0001')->count(),
            'the number submitted by hand was taken instead of the one the system issues');
    }

    public function test_the_suggested_number_saves(): void
    {
        $this->profile('EMP-0004');

        $this->post('/users', $this->payload())->assertRedirect();

        $this->assertDatabaseHas('employee_profiles', ['employee_no' => 'EMP-0005']);
    }

    /**
     * The point of the whole thing. Archiving keeps the employee_profiles row,
     * so a resigned, dismissed or deceased employee's number stays counted and
     * cannot come back to somebody else -- their leave record, their filed CSC
     * Form 6 copies and their audit entries all still carry it.
     */
    public function test_a_number_is_not_reissued_after_the_account_is_archived(): void
    {
        $this->profile('EMP-0001');
        $leaver = \App\Models\EmployeeProfile::where('employee_no', 'EMP-0002')->first()
            ?? $this->profile('EMP-0002');

        $leaver->user->delete();   // archived: resigned, dismissed or died

        $this->assertSame('EMP-0003', \App\Models\EmployeeProfile::nextEmployeeNo(),
            'the sequence went backwards and would hand out a former employee\'s number');

        $this->post('/users', $this->payload())->assertRedirect();
        $this->assertDatabaseHas('employee_profiles', ['employee_no' => 'EMP-0003']);
    }

    /** @param array<string,mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Juan Dela Cruz',
            'username' => 'jdelacruz',
            'email' => 'juan@alicia.gov.ph',
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
