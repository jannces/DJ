<?php

namespace Tests\Feature\Leave;

use App\Models\Department;
use App\Models\EmployeeProfile;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "If abroad, specify" is required once Abroad is the answer, and not before.
 *
 * It used to be required ALWAYS for Vacation Leave -- detail_schema marks it
 * required outright -- so an ordinary vacation spent in Alicia was refused with
 * "The field 'Specify location' is required for Vacation Leave", for a box the
 * form labels "If abroad, specify". The CSC sheet asks for the destination of
 * foreign travel, not for the barangay somebody is spending their leave in.
 *
 * Two halves, doing two different jobs:
 *
 *   - LeavePolicyEngine::conditionallyOptional() is what enforces it. That is
 *     the mechanism this system already had for "required, except when", so
 *     the rule lives beside the one for purpose_other rather than in a second
 *     place that could disagree with it.
 *   - The asterisk appears beside the label when the Abroad radio is checked,
 *     through CSS :has() -- the same idiom the leave-type blocks use. It is a
 *     courtesy, so somebody filling the form knows before they submit. The
 *     HTML `required` attribute cannot be switched on and off by CSS, and a
 *     permanent one would block leave spent in the Philippines.
 */
class AbroadDestinationTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;

    private LeaveType $vl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();

        $this->employee = $this->makeUser('employee');
        EmployeeProfile::factory()->create([
            'user_id' => $this->employee->id,
            'department_id' => Department::factory()->create()->id,
        ]);

        $this->vl = LeaveType::where('code', 'VL')->firstOrFail();
        LeaveBalance::create([
            'user_id' => $this->employee->id, 'leave_type_id' => $this->vl->id,
            'earned' => 15, 'used' => 0, 'balance' => 15,
        ]);
    }

    private function asEmployee(): self
    {
        $this->actingAs($this->employee);
        session(['otp_verified' => true]);

        return $this;
    }

    /** @param array<string,string> $details */
    private function file(array $details): \Illuminate\Testing\TestResponse
    {
        $day = now()->addWeek()->next('Monday')->toDateString();

        return $this->asEmployee()->post('/leave', [
            'leave_type_id' => [$this->vl->id],
            'date_filed' => now()->toDateString(),
            'start_date' => $day,
            'end_date' => $day,
            'commutation' => '0',
            'applicant_signature' => $this->employee->name,
            'details' => $details,
        ]);
    }

    /** Abroad with no destination is refused. */
    public function test_abroad_without_a_destination_is_refused(): void
    {
        $this->file(['location' => 'abroad'])
            ->assertSessionHasErrors('policy');

        $this->assertDatabaseCount('leave_requests', 0);
    }

    /** An empty string counts as not saying. */
    public function test_abroad_with_a_blank_destination_is_refused(): void
    {
        $this->file(['location' => 'abroad', 'location_specify' => ''])
            ->assertSessionHasErrors('policy');

        $this->assertDatabaseCount('leave_requests', 0);
    }

    /** And the refusal names the field, in the words the form uses. */
    public function test_the_message_names_what_is_missing(): void
    {
        $this->file(['location' => 'abroad']);

        $this->assertStringContainsString('Specify location',
            implode(' ', session('errors')->get('policy')));
    }

    /** Abroad with a destination goes through. */
    public function test_abroad_with_a_destination_is_accepted(): void
    {
        $this->file(['location' => 'abroad', 'location_specify' => 'Singapore'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame('Singapore',
            LeaveRequest::firstOrFail()->details['location_specify'] ?? null);
    }

    /**
     * Leave spent in the Philippines still files with the box empty.
     *
     * This is why the rule is required_if and not required: for most leave the
     * field is correctly blank, and a permanent requirement would stop every
     * ordinary vacation being filed.
     */
    public function test_leave_within_the_philippines_needs_no_destination(): void
    {
        $this->file(['location' => 'within_ph'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseCount('leave_requests', 1);
    }

    /** The asterisk is in the form, carrying the class that reveals it. */
    public function test_the_label_carries_a_conditional_asterisk(): void
    {
        $this->asEmployee()->get('/leave/apply')
            ->assertOk()
            ->assertSee('<span class="req lf-req-abroad">*</span>', false);
    }

    /**
     * And the stylesheet hides it until Abroad is chosen.
     *
     * A source assertion, because the suite renders no CSS: without it the
     * asterisk would show on every leave type and mark as required a field
     * that usually is not.
     */
    public function test_the_asterisk_is_hidden_until_abroad_is_chosen(): void
    {
        $css = file_get_contents(public_path('css/app.css'));

        $this->assertStringContainsString('.lf-req-abroad{ display:none; }', $css,
            'the asterisk is shown for every leave type, not only for one spent abroad');

        $this->assertStringContainsString(
            '.lf-g:has(input[name="details[location]"][value="abroad"]:checked) .lf-req-abroad', $css,
            'nothing reveals the asterisk when Abroad is chosen');
    }
}
