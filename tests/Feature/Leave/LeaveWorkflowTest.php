<?php

namespace Tests\Feature\Leave;

use App\Models\Department;
use App\Models\EmployeeProfile;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Leave\ApprovalWorkflowService;
use App\Services\Leave\LeaveApplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LeaveWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;
    private User $head;
    private User $hr;
    private User $mayor;
    private LeaveType $vl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();

        $dept = Department::factory()->create();
        $this->vl = LeaveType::where('code', 'VL')->first();

        $this->employee = $this->makeUser('employee');
        EmployeeProfile::factory()->create(['user_id' => $this->employee->id, 'department_id' => $dept->id]);
        LeaveBalance::create(['user_id' => $this->employee->id, 'leave_type_id' => $this->vl->id, 'earned' => 10, 'used' => 0, 'balance' => 10]);

        $this->head = $this->makeUser('department-head');
        EmployeeProfile::factory()->create(['user_id' => $this->head->id, 'department_id' => $dept->id]);

        $this->hr = $this->makeUser('hr');
        EmployeeProfile::factory()->create(['user_id' => $this->hr->id, 'department_id' => $dept->id]);

        $this->mayor = $this->makeUser('mayor');
        EmployeeProfile::factory()->create(['user_id' => $this->mayor->id, 'department_id' => $dept->id]);
    }

    private function fileRequest(float $expectDays = 3): LeaveRequest
    {
        return app(LeaveApplicationService::class)->submit($this->employee, $this->vl, [
            'date_filed' => '2026-07-01',
            'start_date' => '2026-07-13', // Mon
            'end_date' => '2026-07-15',   // Wed → 3 working days
            'purpose' => 'Family matters',
            'details' => ['location' => 'within_ph', 'location_specify' => 'Alicia'],
            'applicant_signature' => $this->employee->name,
        ]);
    }

    public function test_full_approval_chain_deducts_credits_and_notifies(): void
    {
        $request = $this->fileRequest();
        $this->assertSame(3.0, (float) $request->working_days);
        $this->assertSame(LeaveRequest::STATUS_DEPT_REVIEW, $request->status);
        $this->assertDatabaseCount('approvals', 3);

        $workflow = app(ApprovalWorkflowService::class);
        $workflow->act($request->fresh(), $this->head, 'approved', ['comments' => 'Recommended']);
        $this->assertSame(LeaveRequest::STATUS_HR_REVIEW, $request->fresh()->status);

        $workflow->act($request->fresh(), $this->hr, 'approved', ['certified_balances' => ['vacation_balance' => 10]]);
        $this->assertSame(LeaveRequest::STATUS_FINAL_REVIEW, $request->fresh()->status);

        $workflow->act($request->fresh(), $this->mayor, 'approved', ['days_with_pay' => 3, 'days_without_pay' => 0]);
        $approved = $request->fresh();
        $this->assertSame(LeaveRequest::STATUS_APPROVED, $approved->status);

        $balance = LeaveBalance::where('user_id', $this->employee->id)->where('leave_type_id', $this->vl->id)->first();
        $this->assertEquals(7, (float) $balance->balance); // 10 - 3
        $this->assertDatabaseHas('leave_history', ['leave_request_id' => $request->id, 'entry_type' => 'deduction']);
        $this->assertNotEmpty($this->employee->fresh()->notifications);
    }

    public function test_department_head_rejection_stops_the_workflow(): void
    {
        $request = $this->fileRequest();
        app(ApprovalWorkflowService::class)->act($request, $this->head, 'rejected', ['comments' => 'Insufficient staffing']);

        $rejected = $request->fresh();
        $this->assertSame(LeaveRequest::STATUS_REJECTED, $rejected->status);
        $this->assertSame('Insufficient staffing', $rejected->disapproval_reason);
        // No deduction happened.
        $balance = LeaveBalance::where('user_id', $this->employee->id)->where('leave_type_id', $this->vl->id)->first();
        $this->assertEquals(10, (float) $balance->balance);
    }

    public function test_cannot_file_more_days_than_available_credits(): void
    {
        LeaveBalance::where('user_id', $this->employee->id)->update(['balance' => 2, 'earned' => 2]);

        $this->expectException(ValidationException::class);
        app(LeaveApplicationService::class)->submit($this->employee, $this->vl, [
            'date_filed' => '2026-07-01',
            'start_date' => '2026-07-13',
            'end_date' => '2026-07-17', // 5 days > 2 balance
            'applicant_signature' => $this->employee->name,
            'details' => ['location' => 'within_ph', 'location_specify' => 'Alicia'],
        ]);
    }

    public function test_concurrent_approvals_cannot_overspend_credits(): void
    {
        // Two requests of 6 days each against a 10-day balance: only one can approve.
        LeaveBalance::where('user_id', $this->employee->id)->update(['balance' => 10, 'earned' => 10]);
        $r1 = app(LeaveApplicationService::class)->submit($this->employee, $this->vl, [
            'date_filed' => '2026-07-01', 'start_date' => '2026-07-13', 'end_date' => '2026-07-20',
            'applicant_signature' => $this->employee->name, 'details' => ['location' => 'within_ph', 'location_specify' => 'x'],
        ]);
        $r2 = app(LeaveApplicationService::class)->submit($this->employee, $this->vl, [
            'date_filed' => '2026-07-01', 'start_date' => '2026-07-27', 'end_date' => '2026-08-03',
            'applicant_signature' => $this->employee->name, 'details' => ['location' => 'within_ph', 'location_specify' => 'x'],
        ]);

        $wf = app(ApprovalWorkflowService::class);
        foreach ([$r1, $r2] as $r) {
            $wf->act($r->fresh(), $this->head, 'approved');
            $wf->act($r->fresh(), $this->hr, 'approved');
        }

        // First final approval succeeds.
        $wf->act($r1->fresh(), $this->mayor, 'approved', ['days_with_pay' => 6]);
        $this->assertSame(LeaveRequest::STATUS_APPROVED, $r1->fresh()->status);

        // Second must fail (only 4 credits left, needs 6).
        $this->expectException(\RuntimeException::class);
        $wf->act($r2->fresh(), $this->mayor, 'approved', ['days_with_pay' => 6]);
    }

    public function test_sick_leave_over_five_days_requires_a_medical_certificate(): void
    {
        $sl = LeaveType::where('code', 'SL')->first();
        LeaveBalance::create(['user_id' => $this->employee->id, 'leave_type_id' => $sl->id, 'earned' => 20, 'balance' => 20, 'used' => 0]);

        $engine = app(\App\Services\Leave\LeavePolicyEngine::class);
        $docs = $engine->requiredDocuments($sl, 6.0);
        $types = array_column($docs, 'type');
        $this->assertContains('medical_certificate', $types);

        // 2-day sick leave (HR rule >2) → not required
        $docsShort = $engine->requiredDocuments($sl, 2.0);
        $this->assertNotContains('medical_certificate', array_column($docsShort, 'type'));
    }
}
