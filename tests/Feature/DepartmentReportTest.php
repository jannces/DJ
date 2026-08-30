<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\EmployeeProfile;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\User;
use App\Services\Reports\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reports scoped to the office the reader heads.
 *
 * A head could see today's absences on their dashboard but had no way to look
 * back over a quarter, and every report in the catalogue was LGU-wide — so the
 * only way to give them one was leave.requests.view-all, which is every
 * office's applications and the thing the scoping exists to prevent.
 *
 * These reuse the same builders. The one difference is that the office is
 * supplied rather than chosen, and supplied from the record: a head who sends
 * ?department=3 gets their own office, not the Treasurer's.
 */
class DepartmentReportTest extends TestCase
{
    use RefreshDatabase;

    private Department $mine;

    private Department $theirs;

    private User $head;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();

        $position = Position::factory()->create();
        $this->mine = Department::create(['name' => 'Municipal Engineering Office', 'code' => 'MEO']);
        $this->theirs = Department::create(['name' => "Municipal Treasurer's Office", 'code' => 'MTO']);

        $this->head = $this->makeUser('department-head');
        EmployeeProfile::factory()->create([
            'user_id' => $this->head->id,
            'department_id' => $this->mine->id, 'position_id' => $position->id,
        ]);
        $this->mine->update(['head_user_id' => $this->head->id]);

        $type = LeaveType::where('code', 'VL')->firstOrFail();

        foreach ([[$this->mine, 'Engineering Clerk'], [$this->theirs, 'Treasury Clerk']] as [$office, $name]) {
            $staff = $this->makeUser('employee');
            $staff->update(['name' => $name]);
            EmployeeProfile::factory()->create([
                'user_id' => $staff->id,
                'department_id' => $office->id, 'position_id' => $position->id,
            ]);
            LeaveRequest::factory()->create([
                'user_id' => $staff->id, 'leave_type_id' => $type->id,
                'status' => 'pending',
                'start_date' => now()->startOfMonth()->addDays(2),
                'end_date' => now()->startOfMonth()->addDays(3),
            ]);
            // Both offices carry credits, so the balance report having only
            // one of them in it means the scope held rather than that the
            // other office had nothing to show.
            \App\Models\LeaveBalance::factory()->create([
                'user_id' => $staff->id, 'leave_type_id' => $type->id,
            ]);
        }

        $this->actingAs($this->head);
        session(['otp_verified' => true]);
    }

    // ----------------------------------------------------------- what is offered

    public function test_the_head_is_offered_their_own_office_and_nothing_wider(): void
    {
        $visible = ReportService::visibleTo($this->head);

        $this->assertArrayHasKey('department', $visible);
        $this->assertCount(3, $visible['department']);
        $this->assertArrayNotHasKey('leave', $visible, 'the LGU-wide reports are on offer');
        $this->assertArrayNotHasKey('security', $visible);
    }

    public function test_the_reports_page_shows_the_office_group(): void
    {
        $this->get('/reports')->assertOk()
            ->assertSee('My office')
            ->assertSee('Leave in my office')
            ->assertSee('Waiting on me')
            ->assertSee('Leave balances in my office')
            ->assertDontSee('Employee Leave Report');
    }

    // ------------------------------------------------------- what they can read

    public function test_a_report_covers_their_office_only(): void
    {
        $this->get('/reports/my-office-leave')->assertOk()
            ->assertSee('Engineering Clerk')
            ->assertDontSee('Treasury Clerk');
    }

    /**
     * The one that matters. The office is overwritten, not defaulted, so it
     * cannot be talked out of by the request.
     */
    public function test_asking_for_another_office_still_returns_their_own(): void
    {
        $this->get('/reports/my-office-leave?department='.$this->theirs->id)->assertOk()
            ->assertSee('Engineering Clerk')
            ->assertDontSee('Treasury Clerk');
    }

    public function test_the_pending_report_is_scoped_too(): void
    {
        $this->get('/reports/my-office-pending')->assertOk()
            ->assertSee('Engineering Clerk')
            ->assertDontSee('Treasury Clerk');
    }

    public function test_the_balance_report_is_scoped_too(): void
    {
        $this->get('/reports/my-office-balances')->assertOk()
            ->assertSee('Engineering Clerk')
            ->assertDontSee('Treasury Clerk');
    }

    /** The exports carry the same scope as the page. */
    public function test_an_export_cannot_reach_further_than_the_page(): void
    {
        $response = $this->get('/reports/my-office-leave?format=xlsx&department='.$this->theirs->id);

        $this->assertContains($response->getStatusCode(), [200, 302]);
        // Nothing from the other office may appear in the download either.
        if ($response->getStatusCode() === 200) {
            $this->assertStringNotContainsString('Treasury Clerk', $response->streamedContent() ?: '');
        }
    }

    // -------------------------------------------------------------- the edges

    /** Heading no office is not a reason to be shown a report that refuses. */
    public function test_a_head_of_nothing_is_refused_and_offered_nothing(): void
    {
        $other = $this->makeUser('department-head');
        $this->actingAs($other);
        session(['otp_verified' => true]);

        $this->assertSame([], ReportService::visibleTo($other));
        $this->get('/reports/my-office-leave')->assertForbidden();
    }

    /** The LGU-wide reports stay out of reach whatever is typed. */
    public function test_a_head_cannot_open_an_lgu_wide_report(): void
    {
        $this->get('/reports/employee-leave')->assertForbidden();
        $this->get('/reports/intrusion')->assertForbidden();
    }

    /** Nobody else's view of the reports page changed. */
    public function test_hr_and_the_mayor_are_unaffected(): void
    {
        foreach (['hr', 'mayor'] as $slug) {
            $visible = ReportService::visibleTo($this->makeUser($slug));

            $this->assertArrayHasKey('leave', $visible, $slug.' lost the leave reports');
            $this->assertArrayNotHasKey('department', $visible,
                $slug.' is offered reports for an office they do not head');
        }
    }
}
