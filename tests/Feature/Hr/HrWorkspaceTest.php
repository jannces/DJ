<?php

namespace Tests\Feature\Hr;

use App\Models\Department;
use App\Models\EmployeeProfile;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Leave\LeaveApplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The HR workspace split is presentation only: these tests assert the pages
 * render for the right people and show the right context, not that any
 * workflow, balance or permission behaviour changed.
 */
class HrWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();

        $dept = Department::factory()->create();
        $this->hr = $this->makeUser('hr');
        EmployeeProfile::factory()->create(['user_id' => $this->hr->id, 'department_id' => $dept->id]);
        LeaveBalance::create([
            'user_id' => $this->hr->id,
            'leave_type_id' => LeaveType::where('code', 'VL')->value('id'),
            'earned' => 10, 'used' => 4, 'balance' => 6,
        ]);
    }

    private function actAsHr(): void
    {
        $this->actingAs($this->hr);
        session(['otp_verified' => true]);
    }

    public function test_overview_shows_the_personal_context_not_lgu_wide_figures(): void
    {
        $this->actAsHr();

        $response = $this->get('/dashboard');

        $response->assertOk()
            ->assertViewIs('dashboard.overview')
            ->assertSee('My Leave Balance')
            ->assertSee('My Leave Requests')
            ->assertDontSee('Total Employees');
    }

    public function test_hr_dashboard_carries_the_organisation_wide_figures(): void
    {
        $this->actAsHr();

        $this->get('/hr/dashboard')
            ->assertOk()
            ->assertViewIs('dashboard.hr')
            ->assertSee('Total Employees')
            ->assertSee('Leave Application Trend');
    }

    public function test_employees_without_the_hr_permission_keep_their_own_dashboard(): void
    {
        $employee = $this->makeUser('employee');
        $this->actingAs($employee);
        session(['otp_verified' => true]);

        $this->get('/dashboard')->assertOk()->assertViewIs('dashboard.index');
        $this->get('/hr/dashboard')->assertForbidden();
    }

    public function test_leave_approvals_queue_renders_the_hr_template(): void
    {
        $this->actAsHr();

        $this->get('/review/hr')
            ->assertOk()
            ->assertViewIs('leave.review-hr')
            ->assertSee('Leave Approvals');
    }

    public function test_request_detail_reports_the_existing_approval_state(): void
    {
        $employee = $this->makeUser('employee');
        $dept = Department::factory()->create();
        EmployeeProfile::factory()->create(['user_id' => $employee->id, 'department_id' => $dept->id]);
        $vl = LeaveType::where('code', 'VL')->first();
        LeaveBalance::create(['user_id' => $employee->id, 'leave_type_id' => $vl->id, 'earned' => 10, 'used' => 0, 'balance' => 10]);

        $request = app(LeaveApplicationService::class)->submit($employee, $vl, [
            'date_filed' => '2026-07-01',
            'start_date' => '2026-07-13',
            'end_date' => '2026-07-15',
            'purpose' => 'Family matters',
            'details' => ['location' => 'within_ph', 'location_specify' => 'Alicia'],
            'applicant_signature' => $employee->name,
        ]);

        $this->actAsHr();
        $response = $this->get("/leave/{$request->id}");

        $response->assertOk()->assertSee('Approval Authority')->assertSee('Approval History');

        // The request sits at the Department Head step, so HR must be told it
        // cannot act — the workflow, not the interface, decides that.
        $this->assertSame(LeaveRequest::STATUS_DEPT_REVIEW, $request->fresh()->status);
        $response->assertSee('Action restricted');
    }

    public function test_hr_sees_the_reorganised_navigation(): void
    {
        $this->actAsHr();

        $this->get('/dashboard')
            ->assertSee('Overview')
            ->assertSee('HR Management')
            ->assertSee('Leave Approvals')
            ->assertSee('LGU Alicia')
            ->assertDontSee('LeavePro');
    }
}
