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
        return ['mayor' => ['mayor'], 'vice mayor' => ['vice-mayor'], 'hr' => ['hr']];
    }

    // ------------------------------------------------------ department head

    public function test_department_head_can_no_longer_approve(): void
    {
        $request = $this->fileRequest();
        $head = $this->approver('department-head');

        $this->assertFalse($head->hasPermission('leave.approve.final'));

        $this->expectException(ValidationException::class);
        app(ApprovalWorkflowService::class)->act($request, $head, 'approved');
    }

    public function test_department_head_is_denied_the_approval_queue(): void
    {
        $head = $this->approver('department-head');
        $this->actingAs($head);
        session(['otp_verified' => true]);

        $this->get('/review')->assertForbidden();
    }

    public function test_department_head_cannot_post_a_decision_directly(): void
    {
        $request = $this->fileRequest();
        $head = $this->approver('department-head');
        $this->actingAs($head);
        session(['otp_verified' => true]);

        // Hiding the button is not the control — the endpoint itself refuses.
        $this->post("/review/{$request->id}/act", ['action' => 'approved'])->assertForbidden();
        $this->assertSame(LeaveRequest::STATUS_PENDING, $request->fresh()->status);
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
        $workflow->act($request->fresh(), $this->approver('vice-mayor'), 'approved');
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
            ->act($request, $this->approver('vice-mayor'), 'rejected', ['comments' => 'Short staffed']);

        $this->actingAs($this->employee);
        session(['otp_verified' => true]);

        $this->get("/leave/{$request->id}/timeline")
            ->assertOk()
            ->assertSee('Rejected by Vice Mayor')
            ->assertSee('Short staffed');
    }
}
