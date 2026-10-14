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
 * Where the leave will be spent, and where that place is printed.
 *
 * The LGU records the place for EVERY leave, not only one spent abroad, so
 * "If abroad, specify" is required whichever answer is given and the asterisk
 * beside it is permanent.
 *
 * That makes the printed sheet the thing to get right. CSC Form No. 6 carries
 * its specify-rule beside "Abroad (Specify)", because that is the only one the
 * official form asks about -- so printing the place there regardless meant a
 * leave spent in Alicia was written onto the Abroad line, on a signed
 * government form, with the Abroad box unticked. Each row now carries its own
 * value and shows it only when it is the one chosen.
 */
class LeaveLocationTest extends TestCase
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

    private function sheet(LeaveRequest $request): string
    {
        return $this->asEmployee()->get("/leave/{$request->id}/preview")->assertOk()->getContent();
    }

    // ------------------------------------------------------- what is required

    /** Abroad with no place is refused. */
    public function test_abroad_without_a_place_is_refused(): void
    {
        $this->file(['location' => 'abroad'])->assertSessionHasErrors('policy');

        $this->assertDatabaseCount('leave_requests', 0);
    }

    /**
     * And so is leave within the Philippines with no place.
     *
     * This is the half that distinguishes the rule from the CSC form's own:
     * the sheet only asks where ABROAD, the LGU asks where at all.
     */
    public function test_leave_within_the_philippines_without_a_place_is_refused(): void
    {
        $this->file(['location' => 'within_ph'])->assertSessionHasErrors('policy');

        $this->assertDatabaseCount('leave_requests', 0);
    }

    /** A blank string is not an answer either. */
    public function test_a_blank_place_is_refused(): void
    {
        $this->file(['location' => 'within_ph', 'location_specify' => ''])
            ->assertSessionHasErrors('policy');

        $this->assertDatabaseCount('leave_requests', 0);
    }

    /** With a place, either answer files. */
    public function test_either_answer_files_once_the_place_is_given(): void
    {
        $this->file(['location' => 'abroad', 'location_specify' => 'Singapore'])
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('Singapore', LeaveRequest::firstOrFail()->details['location_specify']);
    }

    /** The asterisk is permanent, not revealed by the radio. */
    public function test_the_label_is_marked_required(): void
    {
        $html = $this->asEmployee()->get('/leave/apply')->assertOk()->getContent();

        // Matched loosely across the line break, so re-indenting the Blade does
        // not fail a test that is about the asterisk being there.
        $this->assertMatchesRegularExpression(
            '/If abroad, specify\s*<span class="req">\*<\/span>/', $html,
            'the destination is not marked required on the form');

        $this->assertStringNotContainsString('lf-req-abroad', $html,
            'the conditional asterisk is back, so the field looks optional for domestic leave');
    }

    // --------------------------------------------------------- what is printed

    /**
     * A domestic place prints on the "Within the Philippines" rule.
     *
     * Not on the Abroad rule, which is where it used to go: a leave spent in
     * Alicia was printed against "Abroad (Specify)" with that box unticked.
     */
    public function test_a_domestic_place_prints_on_the_domestic_line(): void
    {
        $this->file(['location' => 'within_ph', 'location_specify' => 'Alicia, Isabela']);
        $sheet = $this->sheet(LeaveRequest::firstOrFail());

        $within = strpos($sheet, 'Within the Philippines');
        $abroad = strpos($sheet, 'Abroad (Specify)');
        $place = strpos($sheet, 'Alicia, Isabela');

        $this->assertNotFalse($place, 'the place was not printed at all');
        $this->assertTrue($place > $within && $place < $abroad,
            'the place is printed on the Abroad line for a leave spent in the Philippines');
    }

    /** And a foreign one still prints on the Abroad rule, as the CSC form expects. */
    public function test_a_foreign_place_prints_on_the_abroad_line(): void
    {
        $this->file(['location' => 'abroad', 'location_specify' => 'Singapore']);
        $sheet = $this->sheet(LeaveRequest::firstOrFail());

        $abroad = strpos($sheet, 'Abroad (Specify)');
        $place = strpos($sheet, 'Singapore');

        $this->assertNotFalse($place, 'the place was not printed at all');
        $this->assertTrue($place > $abroad,
            'the place is printed above the Abroad line');
    }

    /** The place appears once, never on both rules. */
    public function test_the_place_is_printed_on_one_line_only(): void
    {
        $this->file(['location' => 'within_ph', 'location_specify' => 'Alicia, Isabela']);

        $this->assertSame(1, substr_count($this->sheet(LeaveRequest::firstOrFail()), 'Alicia, Isabela'),
            'the place is printed on both rules at once');
    }
}
