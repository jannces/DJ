<?php

namespace Tests\Feature\Leave;

use App\Models\Department;
use App\Models\EmployeeProfile;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Leave\LeaveApplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What CSC Form No. 6 says about who did what.
 *
 * The form has three signature blocks and, after this change, three different
 * people in them:
 *
 *   7.A  the HR officer who certified the credits and decided the application
 *   7.B  the head of the applicant's OWN office, who was notified and signs by
 *        hand — the two tick boxes print empty, because nobody in the system
 *        ticked them
 *   foot the Municipal Mayor, as head of agency
 *
 * These are assertions about a legal document, which is why they are here
 * rather than folded into the workflow test: the workflow can be right while
 * the paper the LGU files is wrong.
 */
class PrintedFormTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;

    private User $head;

    private LeaveType $vl;

    private Department $office;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();

        $this->office = Department::create(['name' => 'Municipal Engineering Office', 'code' => 'MEO']);
        $position = Position::factory()->create();
        $this->vl = LeaveType::where('code', 'VL')->firstOrFail();

        $this->employee = $this->makeUser('employee');
        $this->employee->update(['name' => 'Juan Dela Cruz']);
        EmployeeProfile::factory()->create([
            'user_id' => $this->employee->id, 'employee_no' => 'EMP-0001',
            'first_name' => 'Juan', 'last_name' => 'Dela Cruz',
            'department_id' => $this->office->id, 'position_id' => $position->id,
        ]);

        $this->head = $this->makeUser('department-head');
        $this->head->update(['name' => 'Engr. Maria S. Bumanglag']);
        EmployeeProfile::factory()->create([
            'user_id' => $this->head->id, 'employee_no' => 'EMP-0002',
            'department_id' => $this->office->id, 'position_id' => $position->id,
        ]);
        $this->office->update(['head_user_id' => $this->head->id]);

        LeaveBalance::create([
            'user_id' => $this->employee->id, 'leave_type_id' => $this->vl->id,
            'earned' => 30, 'used' => 0, 'balance' => 30,
        ]);
    }

    private function file(): LeaveRequest
    {
        return app(LeaveApplicationService::class)->submit($this->employee->fresh(), $this->vl, [
            'date_filed' => '2026-07-01', 'start_date' => '2026-07-13', 'end_date' => '2026-07-15',
            'purpose' => 'Family matters',
            'details' => ['location' => 'within_ph', 'location_specify' => 'Alicia, Isabela'],
            'applicant_signature' => 'Juan Dela Cruz',
        ]);
    }

    /** Approve through the endpoint, which is what snapshots the certification. */
    private function approve(LeaveRequest $request): User
    {
        $hr = $this->makeUser('hr');
        $hr->update(['name' => 'Atty. Mariah Leah D. Valerozo-Garcia']);
        EmployeeProfile::factory()->create([
            'user_id' => $hr->id, 'employee_no' => 'EMP-0003',
            'department_id' => $this->office->id, 'position_id' => Position::factory()->create()->id,
        ]);

        $this->actingAs($hr);
        session(['otp_verified' => true]);
        $this->post("/review/{$request->id}/act", [
            'action' => 'approved', 'days_with_pay' => 3, 'days_without_pay' => 0,
            'signature' => $hr->name,
        ])->assertRedirect();

        return $hr;
    }

    private function sheet(LeaveRequest $request): string
    {
        $this->actingAs($this->employee);
        session(['otp_verified' => true]);

        return $this->get("/leave/{$request->id}/preview")->assertOk()->getContent();
    }

    // ------------------------------------------------------------------ 7.B

    public function test_box_7b_carries_the_applicants_own_department_head(): void
    {
        $html = $this->sheet($this->file());

        $this->assertStringContainsString('Engr. Maria S. Bumanglag', $html,
            'box 7.B does not name the head of the applicant\'s office');
    }

    /**
     * The head takes no action in the system, so the system ticks nothing.
     *
     * A ticked box is a recommendation, and recording one nobody made would be
     * the system signing on an officer's behalf.
     */
    public function test_the_7b_boxes_print_empty_even_after_a_decision(): void
    {
        $request = $this->file();
        $this->approve($request);

        $html = $this->sheet($request);

        // The sheet marks a ticked box with `csc-box-on` (see the $tick
        // closure in preview-form.blade.php); an approved application must
        // still leave both of 7.B's boxes bare.
        $seven_b = substr($html, strpos($html, '7.B RECOMMENDATION'));
        $seven_b = substr($seven_b, 0, strpos($seven_b, '7.C APPROVED FOR'));

        $this->assertStringContainsString('csc-box', $seven_b,
            'the boxes are not being rendered at all, so their state proves nothing');
        $this->assertStringNotContainsString('csc-box-on', $seven_b,
            'the system ticked a recommendation nobody made');
    }

    /** The head's name survives a change of office head. */
    public function test_box_7b_names_the_head_at_the_time_of_filing(): void
    {
        $request = $this->file();

        $successor = $this->makeUser('department-head');
        $successor->update(['name' => 'Engr. Pedro T. Ramos']);
        EmployeeProfile::factory()->create([
            'user_id' => $successor->id, 'employee_no' => 'EMP-0004',
            'department_id' => $this->office->id, 'position_id' => Position::factory()->create()->id,
        ]);
        $this->office->update(['head_user_id' => $successor->id]);

        $html = $this->sheet($request->fresh());

        $this->assertStringContainsString('Engr. Maria S. Bumanglag', $html);
        $this->assertStringNotContainsString('Engr. Pedro T. Ramos', $html,
            'the form names whoever heads the office today, not who was informed');
    }

    // ------------------------------------------------------------------ 7.A

    public function test_box_7a_is_signed_by_the_officer_who_decided(): void
    {
        $request = $this->file();
        $hr = $this->approve($request);

        $this->assertStringContainsString($hr->name, $this->sheet($request->fresh()));
    }

    /**
     * The certification states the credits AS CERTIFIED, not as they stand now.
     *
     * Approving deducts the days, so reading the live ledger made a reprinted
     * form subtract them twice: 30 earned showed as "27 earned, less 3,
     * balance 24". The figures are snapshotted at the decision instead.
     */
    public function test_box_7a_shows_the_credits_as_certified_not_as_they_stand_now(): void
    {
        $request = $this->file();
        $this->approve($request);

        $html = $this->sheet($request->fresh());
        $seven_a = substr($html, strpos($html, '7.A CERTIFICATION'));
        $seven_a = substr($seven_a, 0, strpos($seven_a, '7.B RECOMMENDATION'));

        $this->assertStringContainsString('30.000', $seven_a, 'total earned is not the certified figure');
        $this->assertStringContainsString('27.000', $seven_a, 'the balance is not earned less this application');
        $this->assertStringNotContainsString('24.000', $seven_a, 'the days are deducted twice on the form');
    }

    // ----------------------------------------------------------------- foot

    /** The Mayor no longer decides, and still signs as head of agency. */
    public function test_the_mayor_still_signs_at_the_foot(): void
    {
        $request = $this->file();
        $this->approve($request);

        $this->assertStringContainsString(
            SystemSetting::get('general.mayor_name', 'ATTY. JOEL AMOS P. ALEJANDRO, CPA'),
            $this->sheet($request->fresh())
        );
    }

    // ------------------------------------------------------------- timeline

    public function test_the_timeline_records_that_the_head_was_told(): void
    {
        $request = $this->file();

        $this->actingAs($this->employee);
        session(['otp_verified' => true]);

        $this->get("/leave/{$request->id}/timeline")->assertOk()
            ->assertSee('Department Head Notified')
            ->assertSee('Engr. Maria S. Bumanglag')
            ->assertSee('No approval is needed from them.', false)
            ->assertSee('Waiting for HR to validate and decide.', false);
    }

    /** An office with no head shows no notification line rather than a blank one. */
    public function test_the_timeline_omits_the_line_when_there_is_no_head(): void
    {
        $this->office->update(['head_user_id' => null]);
        $request = $this->file();

        $this->actingAs($this->employee);
        session(['otp_verified' => true]);

        $this->get("/leave/{$request->id}/timeline")->assertOk()
            ->assertDontSee('Department Head Notified');
    }
}
