<?php

namespace Tests\Feature\Leave;

use App\Models\Approval;
use App\Models\Department;
use App\Models\EmployeeProfile;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Notifications\DepartmentLeaveFiledNotification;
use App\Services\Leave\ApprovalWorkflowService;
use App\Services\Leave\LeaveApplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The approval workflow is a single step decided by HR, and only HR.
 *
 * The applicant's department head is NOTIFIED when an application is filed and
 * can act on none of it. The Mayor reads every application and signs the
 * printed form as head of agency, but does not decide either. Once a decision
 * is recorded nobody can overturn it.
 *
 * Most of this file is about who CANNOT act, which is the half that matters:
 * an approval system is defined by its refusals.
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
     * The head who is notified is the one NAMED on the department, not whoever
     * holds the role and happens to work there — so the test sets the same
     * field the Departments page does.
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

    public function test_hr_approves_alone(): void
    {
        $request = $this->fileRequest();

        app(ApprovalWorkflowService::class)
            ->act($request, $this->approver('hr'), 'approved', ['days_with_pay' => 3]);

        $this->assertSame(LeaveRequest::STATUS_APPROVED, $request->fresh()->status);
        // Credits deducted on that single approval — no further step required.
        $this->assertEquals(27, (float) LeaveBalance::where('user_id', $this->employee->id)->first()->balance);
    }

    public function test_hr_rejects_alone(): void
    {
        $request = $this->fileRequest();

        app(ApprovalWorkflowService::class)
            ->act($request, $this->approver('hr'), 'rejected', ['comments' => 'Not this week']);

        $rejected = $request->fresh();
        $this->assertSame(LeaveRequest::STATUS_REJECTED, $rejected->status);
        $this->assertSame('Not this week', $rejected->disapproval_reason);
        // Nothing deducted on a rejection.
        $this->assertEquals(30, (float) LeaveBalance::where('user_id', $this->employee->id)->first()->balance);
    }

    // ------------------------------------------------------------- the mayor

    /**
     * The Mayor oversees leave and no longer decides it.
     *
     * Asserted at the service, not at the menu: hiding the Leave Approvals
     * entry would leave the route and this method both willing.
     */
    public function test_the_mayor_cannot_decide_an_application(): void
    {
        $mayor = $this->approver('mayor');
        $request = $this->fileRequest();

        $this->assertFalse($mayor->hasPermission('leave.approve.final'));
        $this->assertTrue($mayor->hasPermission('leave.requests.view-all'),
            'the Mayor must still be able to read every application');

        try {
            app(ApprovalWorkflowService::class)->act($request, $mayor, 'approved');
            $this->fail('the Mayor decided an application');
        } catch (ValidationException $e) {
            $this->assertSame(LeaveRequest::STATUS_PENDING, $request->fresh()->status);
        }
    }

    /** And the endpoint refuses them outright, before the service is reached. */
    public function test_the_mayor_is_refused_by_the_approval_route(): void
    {
        $mayor = $this->approver('mayor');
        $request = $this->fileRequest();

        $this->actingAs($mayor);
        session(['otp_verified' => true]);

        $this->get('/review')->assertForbidden();
        $this->post("/review/{$request->id}/act", ['action' => 'approved'])->assertForbidden();
        $this->assertSame(LeaveRequest::STATUS_PENDING, $request->fresh()->status);
    }

    // ------------------------------------------------------ department head

    /**
     * A head is told and can see; they hold no authority over leave at all.
     */
    public function test_a_department_head_sees_and_never_decides(): void
    {
        $head = $this->headOf($this->employee);

        $this->assertTrue($head->hasPermission('leave.review.department'),
            'a head who cannot see their office cannot follow up a notification');
        $this->assertFalse($head->hasPermission('leave.approve.final'));
    }

    /**
     * The application does not stop at the head. This is the case that would
     * be wrong if the notification were still a step.
     */
    public function test_an_application_goes_straight_past_the_head_to_hr(): void
    {
        $head = $this->headOf($this->employee);
        $request = $this->fileRequest();

        $this->assertSame(LeaveRequest::STATUS_PENDING, $request->fresh()->status);
        $this->assertSame(1, $request->fresh()->current_step);

        // The department row exists, already closed, and holds nobody up.
        $row = $request->approvals()->where('step_no', 0)->first();
        $this->assertNotNull($row, 'the notification is not recorded');
        $this->assertSame(Approval::ACTION_NOTIFIED, $row->action);
        $this->assertSame($head->id, $row->approver_id);
        $this->assertNotNull($row->acted_at);
        $this->assertSame(0, $request->approvals()->where('action', Approval::ACTION_PENDING)
            ->where('step_no', 0)->count());
    }

    /** The head actually receives it — in the system only, never by mail. */
    public function test_the_head_is_notified_in_the_system_and_not_by_email(): void
    {
        Notification::fake();
        $head = $this->headOf($this->employee);

        $this->fileRequest();

        Notification::assertSentTo($head, DepartmentLeaveFiledNotification::class,
            function ($notification, array $channels) {
                $this->assertSame(['database'], $channels,
                    'the head was mailed; this notification is in-system only');

                return true;
            });
    }

    /** Nobody else's head hears about it. */
    public function test_only_the_applicants_own_head_is_notified(): void
    {
        Notification::fake();
        $this->headOf($this->employee);
        $stranger = $this->approver('department-head');

        $this->fileRequest();

        Notification::assertNotSentTo($stranger, DepartmentLeaveFiledNotification::class);
    }

    /**
     * The name is SNAPSHOTTED, not looked up.
     *
     * Box 7.B of a form reprinted after the office changes hands must still
     * name whoever headed it on the day of filing — that is who the document
     * says was informed.
     */
    public function test_the_head_recorded_is_the_head_at_the_time_of_filing(): void
    {
        $head = $this->headOf($this->employee);
        $request = $this->fileRequest();

        $successor = $this->makeUser('department-head');
        EmployeeProfile::factory()->create([
            'user_id' => $successor->id,
            'department_id' => $this->employee->employeeProfile->department_id,
        ]);
        $this->employee->employeeProfile->department->update(['head_user_id' => $successor->id]);

        $this->assertSame($head->name,
            app(ApprovalWorkflowService::class)->notifiedHeadName($request->fresh()),
            'the form would name the wrong head after a change of office');
    }

    /** A head cannot act on their own office, by any route. */
    public function test_a_head_cannot_act_on_an_application_at_all(): void
    {
        $head = $this->headOf($this->employee);
        $request = $this->fileRequest();

        $this->actingAs($head);
        session(['otp_verified' => true]);

        // The queue is presentation; the endpoint takes an id, so it is the
        // endpoint that has to refuse.
        $this->get('/review')->assertForbidden();
        $this->post("/review/{$request->id}/act", ['action' => 'approved'])->assertForbidden();

        $this->assertSame(LeaveRequest::STATUS_PENDING, $request->fresh()->status);
        $this->assertSame(Approval::ACTION_PENDING,
            $request->approvals()->where('step_no', 1)->first()->action);
    }

    /** Nor on somebody else's office, which was never theirs to touch. */
    public function test_a_head_cannot_act_on_another_office(): void
    {
        $this->headOf($this->employee);
        $stranger = $this->approver('department-head');
        $request = $this->fileRequest();

        $this->expectException(ValidationException::class);
        app(ApprovalWorkflowService::class)->act($request, $stranger, 'approved');
    }

    /** A head's own leave notifies nobody: they would be telling themselves. */
    public function test_a_head_filing_their_own_leave_notifies_nobody(): void
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
        $this->assertSame(0, $own->approvals()->where('step_no', 0)->count());
    }

    /** An office with no head on record simply records no notification. */
    public function test_an_office_with_no_head_records_no_notification(): void
    {
        $request = $this->fileRequest();

        $this->assertSame(LeaveRequest::STATUS_PENDING, $request->fresh()->status);
        $this->assertSame(0, $request->approvals()->where('step_no', 0)->count());
        $this->assertNull(app(ApprovalWorkflowService::class)->notifiedHeadName($request));
    }

    // ---------------------------------------------------- no double decision

    public function test_a_second_approver_cannot_overturn_an_approval(): void
    {
        $request = $this->fileRequest();
        $workflow = app(ApprovalWorkflowService::class);

        $workflow->act($request, $this->approver('hr'), 'approved', ['days_with_pay' => 3]);
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
        $officer = $this->approver('hr');

        app(ApprovalWorkflowService::class)->act($request, $officer, 'approved', ['days_with_pay' => 3]);

        $approval = $request->fresh()->approvals->first();
        $this->assertSame($officer->id, $approval->approver_id);
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
