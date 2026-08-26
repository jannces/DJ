<?php

namespace Tests\Feature\Leave;

use App\Models\Approval;
use App\Models\Department;
use App\Models\EmployeeProfile;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Leave\ApprovalWorkflowService;
use App\Services\Leave\LeaveApplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The approval workflow is a single step decided by ANY ONE of Mayor, Vice Mayor
 * or HR. Department Head has no authority over leave, and once a decision is
 * recorded nobody can overturn it.
 */
class ApprovalAuthorityTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;
    private LeaveType $vl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();

        $dept = Department::factory()->create();
        $this->vl = LeaveType::where('code', 'VL')->first();

        $this->employee = $this->makeUser('employee');
        EmployeeProfile::factory()->create(['user_id' => $this->employee->id, 'department_id' => $dept->id]);
        LeaveBalance::create([
            'user_id' => $this->employee->id, 'leave_type_id' => $this->vl->id,
            'earned' => 30, 'used' => 0, 'balance' => 30,
        ]);
    }

    /**
     * Make somebody the head of this employee's office.
     *
     * The reviewer is the head NAMED on the department, not whoever holds the
     * role and happens to work there — so the test sets the same field the
     * Departments page does.
     */
    private function headOf(User $employee): User
    {
        $head = $this->makeUser('department-head');
        $department = $employee->employeeProfile->department;

        EmployeeProfile::factory()->create(['user_id' => $head->id, 'department_id' => $department->id]);
        $department->update(['head_user_id' => $head->id]);

        $employee->refresh();

        return $head;
    }

    private function approver(string $roleSlug): User
    {
        $user = $this->makeUser($roleSlug);
        EmployeeProfile::factory()->create(['user_id' => $user->id, 'department_id' => Department::factory()->create()->id]);

        return $user;
    }

    private function fileRequest(): LeaveRequest
    {
        return app(LeaveApplicationService::class)->submit($this->employee, $this->vl, [
            'date_filed' => '2026-07-01',
            'start_date' => '2026-07-13',
            'end_date' => '2026-07-15',
            'purpose' => 'Family matters',
            'details' => ['location' => 'within_ph', 'location_specify' => 'Alicia'],
            'applicant_signature' => $this->employee->name,
        ]);
    }

    // ------------------------------------------------- one step, three roles

    public function test_a_new_request_has_exactly_one_pending_step(): void
    {
        $request = $this->fileRequest();

        $this->assertSame(LeaveRequest::STATUS_PENDING, $request->status);
        $this->assertCount(1, $request->approvals);
        $this->assertSame('authorized', $request->approvals->first()->role_slug);
    }

    /** @dataProvider approverRoles */
    public function test_each_authorized_role_can_approve_alone(string $roleSlug): void
    {
        $request = $this->fileRequest();

        app(ApprovalWorkflowService::class)
            ->act($request, $this->approver($roleSlug), 'approved', ['days_with_pay' => 3]);

        $this->assertSame(LeaveRequest::STATUS_APPROVED, $request->fresh()->status);
        // Credits deducted on that single approval — no further step required.
        $this->assertEquals(27, (float) LeaveBalance::where('user_id', $this->employee->id)->first()->balance);
    }

    /** @dataProvider approverRoles */
    public function test_each_authorized_role_can_reject_alone(string $roleSlug): void
    {
        $request = $this->fileRequest();

        app(ApprovalWorkflowService::class)
            ->act($request, $this->approver($roleSlug), 'rejected', ['comments' => 'Not this week']);

        $rejected = $request->fresh();
        $this->assertSame(LeaveRequest::STATUS_REJECTED, $rejected->status);
        $this->assertSame('Not this week', $rejected->disapproval_reason);
        // Nothing deducted on a rejection.
        $this->assertEquals(30, (float) LeaveBalance::where('user_id', $this->employee->id)->first()->balance);
    }

    public static function approverRoles(): array
    {
        // Vice Mayor was retired: it held exactly what the Mayor holds, and the
        // LGU has five roles. Authority is still a permission rather than a
        // role, so this list is "who holds leave.approve.final", not "who is
        // senior enough".
        return ['mayor' => ['mayor'], 'hr' => ['hr']];
    }

    // ------------------------------------------------------ department head

    /**
     * A head recommends; they do not decide. The distinction is the whole
     * design: their step exists so they are aware and their view is recorded,
     * not so they can end somebody's leave.
     */
    public function test_a_department_head_recommends_and_never_decides(): void
    {
        $head = $this->headOf($this->employee);

        $this->assertTrue($head->hasPermission('leave.review.department'));
        $this->assertFalse($head->hasPermission('leave.approve.final'),
            'a head who could decide could decide any office, not just their own');
    }

    /**
     * The recommendation travels either way — this is the case that would be
     * wrong if the step were a veto.
     */
    public function test_a_head_who_does_not_endorse_still_sends_it_on(): void
    {
        $head = $this->headOf($this->employee);
        $request = $this->fileRequest();

        $this->assertSame(LeaveRequest::STATUS_DEPT_REVIEW, $request->fresh()->status);

        app(ApprovalWorkflowService::class)
            ->act($request, $head, 'rejected', ['comments' => 'Office is short that week']);

        $request->refresh();
        $this->assertSame(LeaveRequest::STATUS_PENDING, $request->status,
            'a head must not be able to end an application');
        $this->assertSame(1, $request->current_step);

        // ...and the Mayor can still approve it, with the objection on record.
        app(ApprovalWorkflowService::class)->act($request, $this->approver('mayor'), 'approved');
        $this->assertSame(LeaveRequest::STATUS_APPROVED, $request->fresh()->status);

        $this->assertSame('Office is short that week',
            $request->approvals()->where('step_no', 0)->first()->comments);
    }

    public function test_a_head_cannot_recommend_on_another_office(): void
    {
        $this->headOf($this->employee);
        $stranger = $this->approver('department-head');
        $request = $this->fileRequest();

        $this->expectException(ValidationException::class);
        app(ApprovalWorkflowService::class)->act($request, $stranger, 'approved');
    }

    public function test_a_head_cannot_post_a_final_decision_directly(): void
    {
        $head = $this->headOf($this->employee);
        $request = $this->fileRequest();

        $this->actingAs($head);
        session(['otp_verified' => true]);

        // The queue is presentation; the endpoint takes an id, so it is the
        // endpoint that has to refuse. A head acting here recommends — the
        // application moves to the deciding step, it is not approved.
        $this->post("/review/{$request->id}/act", ['action' => 'approved']);

        $this->assertSame(LeaveRequest::STATUS_PENDING, $request->fresh()->status,
            'a head posting "approved" must recommend, not approve');
    }

    /** A head's own leave skips the step — nobody reviews their own. */
    public function test_a_heads_own_leave_goes_straight_to_the_mayor_and_hr(): void
    {
        $head = $this->headOf($this->employee);
        LeaveBalance::create([
            'user_id' => $head->id, 'leave_type_id' => $this->vl->id,
            'earned' => 30, 'used' => 0, 'balance' => 30,
        ]);

        $own = app(LeaveApplicationService::class)->submit($head, $this->vl, [
            'date_filed' => '2026-07-01', 'start_date' => '2026-07-20', 'end_date' => '2026-07-21',
            'purpose' => 'Family matters',
            'details' => ['location' => 'within_ph', 'location_specify' => 'Alicia'],
            'applicant_signature' => $head->name,
        ]);

        $this->assertSame(LeaveRequest::STATUS_PENDING, $own->fresh()->status);
        $this->assertSame(1, $own->fresh()->current_step);
        $this->assertSame(0, $own->approvals()->where('step_no', 0)->count(),
            'a step nobody can act is not created');
    }

    /** An office with no head assigned must not strand its people. */
    public function test_an_office_with_no_head_skips_the_step(): void
    {
        $request = $this->fileRequest();

        $this->assertSame(LeaveRequest::STATUS_PENDING, $request->fresh()->status);
        $this->assertSame(0, $request->approvals()->where('step_no', 0)->count());
    }

    /**
     * One absent head must never strand somebody's leave, so the deciding
     * officers can act past a pending recommendation — and the timeline says
     * so rather than showing a silent gap.
     */
    public function test_the_mayor_can_decide_past_a_head_who_has_not_acted(): void
    {
        $this->headOf($this->employee);
        $request = $this->fileRequest();
        $this->assertSame(LeaveRequest::STATUS_DEPT_REVIEW, $request->fresh()->status);

        app(ApprovalWorkflowService::class)->act($request, $this->approver('mayor'), 'approved');

        $this->assertSame(LeaveRequest::STATUS_APPROVED, $request->fresh()->status);
        $this->assertSame(\App\Models\Approval::ACTION_SKIPPED,
            $request->approvals()->where('step_no', 0)->first()->action);
    }

    /**
     * 7.B of CSC Form No. 6 is the RECOMMENDATION block, and that is where the
     * department head signs. 7.C and 7.D are the decision, signed by the
     * authorized officer — so the two names must not be the same name.
     */
    public function test_the_head_signs_7b_and_the_decider_signs_7c(): void
    {
        $head = $this->headOf($this->employee);
        $request = $this->fileRequest();

        app(ApprovalWorkflowService::class)
            ->act($request, $head, 'approved', ['signature' => 'D. MENDOZA']);
        app(ApprovalWorkflowService::class)
            ->act($request->fresh(), $this->approver('mayor'), 'approved', ['signature' => 'J. ALEJANDRO']);

        $this->actingAs($this->employee);
        session(['otp_verified' => true]);
        $html = $this->get("/leave/{$request->id}/preview")->assertOk()->getContent();

        // 7.B carries the head, under the Department Head rule.
        preg_match('#7\.B RECOMMENDATION(.*?)7\.C#s', $html, $b);
        $this->assertNotEmpty($b, 'the form has no 7.B block');
        $this->assertStringContainsString('D. MENDOZA', $b[1]);
        $this->assertStringContainsString('Department Head', $b[1]);
        $this->assertStringNotContainsString('J. ALEJANDRO', $b[1],
            'the deciding officer is signing the recommendation block');
    }

    /**
     * No head, no signature. A form that printed the Mayor's name against the
     * recommendation would be a document claiming a supervisor endorsed
     * something they never saw.
     */
    public function test_7b_is_blank_when_nobody_recommended(): void
    {
        $request = $this->fileRequest();
        app(ApprovalWorkflowService::class)
            ->act($request, $this->approver('mayor'), 'approved', ['signature' => 'J. ALEJANDRO']);

        $this->actingAs($this->employee);
        session(['otp_verified' => true]);
        $html = $this->get("/leave/{$request->id}/preview")->assertOk()->getContent();

        preg_match('#7\.B RECOMMENDATION(.*?)7\.C#s', $html, $b);
        $this->assertStringNotContainsString('J. ALEJANDRO', $b[1],
            'an unsigned recommendation must stay unsigned');
    }

    /**
     * 7.C's three blanks: days with pay, days without pay, and others (specify).
     * The third had no field, no column and no way to be filled — a blank on an
     * official form that cannot be filled is worse than one left empty, because
     * it looks like a field.
     */
    public function test_7c_carries_all_three_of_its_blanks(): void
    {
        $request = $this->fileRequest();
        $mayor = $this->approver('mayor');

        $this->actingAs($mayor);
        session(['otp_verified' => true]);
        $this->post("/review/{$request->id}/act", [
            'action' => 'approved',
            'days_with_pay' => 2,
            'days_without_pay' => 1,
            'approved_others' => 'commutation requested',
        ]);

        $this->assertSame('commutation requested', $request->fresh()->approved_others);

        $this->actingAs($this->employee);
        $html = $this->get("/leave/{$request->id}/preview")->assertOk()->getContent();

        preg_match('#7\.C APPROVED FOR:(.*?)7\.D#s', $html, $c);
        $this->assertNotEmpty($c, 'the form has no 7.C block');
        $this->assertStringContainsString('commutation requested', $c[1]);
    }

    /**
     * The signature under 7.C used to be the configured Mayor, whoever had
     * actually decided. HR is one of the two deciding officers, so on any leave
     * HR approved the form asserted the Mayor had signed it — a false statement
     * on the document that replaces the paper CSC form.
     */
    public function test_the_form_names_the_officer_who_actually_decided(): void
    {
        $request = $this->fileRequest();

        app(ApprovalWorkflowService::class)
            ->act($request, $this->approver('hr'), 'approved', ['signature' => 'M. VALEROZO']);

        $this->actingAs($this->employee);
        session(['otp_verified' => true]);
        $html = $this->get("/leave/{$request->id}/preview")->assertOk()->getContent();

        $mayorName = \App\Models\SystemSetting::get('general.mayor_name', 'ATTY. JOEL AMOS P. ALEJANDRO, CPA');

        $this->assertStringContainsString('M. VALEROZO', $html);
        $this->assertStringNotContainsString($mayorName, $html,
            'the form is claiming the Mayor signed an application HR decided');
    }

    /** An undecided form is unsigned. A name above the rule says otherwise. */
    public function test_an_undecided_form_carries_no_signature(): void
    {
        $request = $this->fileRequest();

        $this->actingAs($this->employee);
        session(['otp_verified' => true]);
        $html = $this->get("/leave/{$request->id}/preview")->assertOk()->getContent();

        $mayorName = \App\Models\SystemSetting::get('general.mayor_name', 'ATTY. JOEL AMOS P. ALEJANDRO, CPA');
        $this->assertStringNotContainsString($mayorName, $html);

        // ...but the rule is still labelled with who should sign it.
        $this->assertStringContainsString(
            \App\Models\SystemSetting::get('general.mayor_title', 'Municipal Mayor'), $html);
    }

    /** The head sees the request. Certifying credits is HR's step. */
    public function test_a_recommendation_does_not_certify_credits(): void
    {
        $head = $this->headOf($this->employee);
        $request = $this->fileRequest();

        $this->actingAs($head);
        session(['otp_verified' => true]);
        $this->post("/review/{$request->id}/act", ['action' => 'approved']);

        $this->assertNull($request->approvals()->where('step_no', 0)->first()->certified_balances,
            'a recommendation is not a certification');
    }

    // ---------------------------------------------------- no double decision

    public function test_a_second_approver_cannot_overturn_an_approval(): void
    {
        $request = $this->fileRequest();
        $workflow = app(ApprovalWorkflowService::class);

        $workflow->act($request, $this->approver('mayor'), 'approved', ['days_with_pay' => 3]);
        $this->assertSame(LeaveRequest::STATUS_APPROVED, $request->fresh()->status);

        $this->expectException(ValidationException::class);
        $workflow->act($request->fresh(), $this->approver('hr'), 'rejected', ['comments' => 'Changed my mind']);
    }

    public function test_a_second_approver_cannot_overturn_a_rejection(): void
    {
        $request = $this->fileRequest();
        $workflow = app(ApprovalWorkflowService::class);

        $workflow->act($request, $this->approver('hr'), 'rejected', ['comments' => 'No']);
        $this->assertSame(LeaveRequest::STATUS_REJECTED, $request->fresh()->status);

        $this->expectException(ValidationException::class);
        $workflow->act($request->fresh(), $this->approver('hr'), 'approved');
    }

    public function test_the_decision_records_who_acted(): void
    {
        $request = $this->fileRequest();
        $mayor = $this->approver('mayor');

        app(ApprovalWorkflowService::class)->act($request, $mayor, 'approved', ['days_with_pay' => 3]);

        $approval = $request->fresh()->approvals->first();
        $this->assertSame($mayor->id, $approval->approver_id);
        $this->assertSame(Approval::ACTION_APPROVED, $approval->action);
        $this->assertNotNull($approval->acted_at);
        $this->assertNotNull($request->fresh()->decided_at);
    }

    // ------------------------------------------------------------- employees

    public function test_an_employee_cannot_decide_any_application(): void
    {
        $request = $this->fileRequest();
        $other = $this->makeUser('employee');

        $this->expectException(ValidationException::class);
        app(ApprovalWorkflowService::class)->act($request, $other, 'approved');
    }

    public function test_an_approver_cannot_decide_their_own_application(): void
    {
        // Even an authorized officer must not self-approve.
        $hr = $this->approver('hr');
        LeaveBalance::create([
            'user_id' => $hr->id, 'leave_type_id' => $this->vl->id,
            'earned' => 30, 'used' => 0, 'balance' => 30,
        ]);
        $own = app(LeaveApplicationService::class)->submit($hr, $this->vl, [
            'date_filed' => '2026-07-01', 'start_date' => '2026-07-13', 'end_date' => '2026-07-15',
            'details' => ['location' => 'within_ph', 'location_specify' => 'Alicia'],
            'applicant_signature' => $hr->name,
        ]);

        $this->expectException(ValidationException::class);
        app(ApprovalWorkflowService::class)->act($own, $hr, 'approved');
    }

    // -------------------------------------------------- employee-only views

    public function test_employee_sees_the_timeline_for_their_own_request(): void
    {
        $request = $this->fileRequest();
        $this->actingAs($this->employee);
        session(['otp_verified' => true]);

        $this->get("/leave/{$request->id}/timeline")
            ->assertOk()
            ->assertSee('Approval Timeline')
            ->assertSee('Application Submitted')
            ->assertSee('Pending Approval');
    }

    public function test_employee_cannot_open_another_employees_timeline_or_form(): void
    {
        $request = $this->fileRequest();
        $stranger = $this->makeUser('employee');
        $this->actingAs($stranger);
        session(['otp_verified' => true]);

        $this->get("/leave/{$request->id}/timeline")->assertForbidden();
        $this->get("/leave/{$request->id}/preview")->assertForbidden();
    }

    public function test_form_preview_shows_the_submitted_values_read_only(): void
    {
        $request = $this->fileRequest();
        $this->actingAs($this->employee);
        session(['otp_verified' => true]);

        $response = $this->get("/leave/{$request->id}/preview");

        $response->assertOk()
            ->assertSee('APPLICATION FOR LEAVE')
            ->assertSee($request->reference_no)
            ->assertSee('Download Form')
            // A read-only copy carries none of the application form's editable
            // controls. (The page layout always has a CSRF field for the
            // sign-out form, so this checks the sheet's own inputs.)
            ->assertDontSee('name="leave_type_id[]"', false)
            ->assertDontSee('name="details[', false)
            ->assertDontSee('class="csc-input"', false)
            ->assertDontSee('Submit application');
    }

    public function test_the_timeline_names_the_officer_who_decided(): void
    {
        $request = $this->fileRequest();
        app(ApprovalWorkflowService::class)
            ->act($request, $this->approver('hr'), 'rejected', ['comments' => 'Short staffed']);

        $this->actingAs($this->employee);
        session(['otp_verified' => true]);

        $this->get("/leave/{$request->id}/timeline")
            ->assertOk()
            ->assertSee('Rejected by HR')
            ->assertSee('Short staffed');
    }
}
