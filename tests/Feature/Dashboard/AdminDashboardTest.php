<?php

namespace Tests\Feature\Dashboard;

use App\Models\Department;
use App\Models\EmployeeProfile;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The System Administrator's Dashboard — the plain one, not the Security
 * Dashboard.
 *
 * It rendered two counters before this. DashboardService was already computing
 * intrusions today, devices online and devices offline for the same page, and
 * the view discarded all three because they were not in its KPI map. The leave
 * analytics are read-only aggregates about the installation, gated with those
 * system counters rather than on a leave permission — the administrator holds
 * none of the leave permissions and still does not.
 */
class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    private LeaveType $vl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->vl = LeaveType::where('code', 'VL')->firstOrFail();
    }

    private function visit(string $role): \Illuminate\Testing\TestResponse
    {
        $this->actingAs($this->makeUser($role));
        session(['otp_verified' => true]);

        return $this->get('/dashboard');
    }

    private function file(User $user, string $status, string $start, string $end, ?string $filed = null): LeaveRequest
    {
        return LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'leave_type_id' => $this->vl->id,
            'status' => $status,
            'date_filed' => $filed ?? $start,
            'start_date' => $start,
            'end_date' => $end,
        ]);
    }

    public function test_the_administrator_dashboard_carries_the_analytics(): void
    {
        $this->visit('system-admin')
            ->assertOk()
            ->assertSee('Registered users')
            ->assertSee('Applications by outcome')
            ->assertSee('Employees on leave')
            ->assertSee('Most applied leave type')
            ->assertSee('Applications by department')
            // ...alongside the system figures the view used to throw away.
            ->assertSee('Devices online')
            ->assertSee('Intrusions today');
    }

    public function test_an_employee_sees_none_of_it(): void
    {
        $this->visit('employee')
            ->assertOk()
            ->assertDontSee('Applications by outcome')
            ->assertDontSee('Applications by department')
            ->assertDontSee('Devices online')
            // ...but keeps their own page.
            ->assertSee('Credit summary');
    }

    /**
     * The analytics are administration, not approval. An approver's dashboard
     * is unchanged by this work — they reach every one of these figures through
     * the Reports module, which is where a "give me the numbers for period X"
     * question belongs.
     */
    public function test_the_approver_roles_are_left_as_they_were(): void
    {
        foreach (['hr', 'mayor', 'vice-mayor'] as $role) {
            $this->visit($role)
                ->assertOk()
                ->assertDontSee('Applications by outcome');
        }
    }

    /**
     * The pie is part-to-whole, so a slice that renders as a wedge of the wrong
     * size is a lie the reader cannot check. The shares must add up.
     */
    public function test_the_outcome_slices_add_up_to_the_whole(): void
    {
        $employee = $this->makeUser('employee');
        foreach (['approved', 'approved', 'approved', 'rejected'] as $status) {
            $this->file($employee, $status, now()->toDateString(), now()->toDateString());
        }

        $html = $this->visit('system-admin')->assertOk()->getContent();

        // Three approved of four filed, one rejected.
        $this->assertStringContainsString('>75%<', $html);
        $this->assertStringContainsString('>25%<', $html);

        preg_match_all('/class="pie-slice [^"]*"/', $html, $matches);
        $this->assertCount(2, $matches[0], 'a bucket with nothing in it gets no slice');
    }

    public function test_the_outcome_chart_columns_add_up_to_the_year(): void
    {
        $employee = $this->makeUser('employee');
        foreach (['approved', 'approved', 'rejected', 'pending'] as $status) {
            $this->file($employee, $status, now()->toDateString(), now()->toDateString());
        }
        // Cancelled is a withdrawal, not an outcome anybody decided.
        $this->file($employee, 'cancelled', now()->toDateString(), now()->toDateString());

        $outcome = app(DashboardService::class)->applicationsByOutcome((int) now()->year);

        $this->assertSame(2, $outcome['totals']['approved']);
        $this->assertSame(1, $outcome['totals']['rejected']);
        $this->assertSame(1, $outcome['totals']['pending']);
        $this->assertSame(4, $outcome['totals']['total'], 'cancelled applications must not be counted');

        $this->assertCount(12, $outcome['months'], 'the year is always twelve columns, not just the busy ones');
        $this->assertSame(
            $outcome['totals']['total'],
            array_sum(array_column($outcome['months'], 'total')),
            'the columns must add up to the year, or the chart contradicts the counter above it'
        );
    }

    /**
     * The heart of it: one employee off for five days is one employee, not
     * five. A distinct count cannot be recovered from the daily counts, which
     * is why the service keeps the user IDs per day rather than just totals.
     */
    public function test_a_long_leave_counts_as_one_employee_across_the_window(): void
    {
        $employee = $this->makeUser('employee');
        $other = $this->makeUser('employee');

        $start = now()->startOfMonth();
        $this->file($employee, 'approved', $start->toDateString(), $start->copy()->addDays(4)->toDateString());
        $this->file($other, 'approved', $start->toDateString(), $start->toDateString());

        $month = app(DashboardService::class)->onLeaveWindows()['month'];

        $this->assertSame(2, $month['distinct'], 'five days off is still one person');
        $this->assertSame(2, $month['peak'], 'both are out on the first day');
        $this->assertSame(6, array_sum(array_column($month['days'], 'count')),
            'the daily counts still total the employee-days');
    }

    public function test_leave_outside_the_window_is_not_counted_and_leave_across_it_is(): void
    {
        $employee = $this->makeUser('employee');
        $inside = now()->startOfMonth();

        // Spans the start of the month from the month before.
        $this->file($employee, 'approved',
            $inside->copy()->subDays(3)->toDateString(),
            $inside->copy()->addDay()->toDateString());
        // Entirely in the future, well past the window.
        $this->file($this->makeUser('employee'), 'approved',
            now()->addMonths(3)->toDateString(),
            now()->addMonths(3)->addDay()->toDateString());
        // Approved is the only status that puts somebody out of the office.
        $this->file($this->makeUser('employee'), 'pending',
            $inside->toDateString(), $inside->toDateString());

        $month = app(DashboardService::class)->onLeaveWindows()['month'];

        $this->assertSame(1, $month['distinct']);
        $this->assertSame(2, array_sum(array_column($month['days'], 'count')),
            'only the two days that fall inside the month should be counted');
    }

    /**
     * The whole reason this panel is cheap enough not to need a cache.
     *
     * Leave is a date *range*, so all three windows come out of one overlap
     * query and are expanded into days in PHP. Asking the database for a count
     * per day would be thirty-one round trips to rebuild something a few dozen
     * rows already contain — and then a cache would be needed to hide it.
     */
    public function test_all_three_windows_come_from_a_single_query(): void
    {
        $employee = $this->makeUser('employee');
        foreach (range(0, 5) as $offset) {
            $day = now()->startOfMonth()->addDays($offset * 3);
            $this->file($employee, 'approved', $day->toDateString(), $day->copy()->addDay()->toDateString());
        }

        \DB::enableQueryLog();
        $windows = app(DashboardService::class)->onLeaveWindows();
        $queries = \DB::getQueryLog();
        \DB::disableQueryLog();

        $this->assertCount(1, $queries,
            'today, this week and this month should cost one query between them, not one per day');
        $this->assertArrayHasKey('today', $windows);
        $this->assertCount(7, $windows['week']['days']);
    }

    public function test_employees_with_no_department_are_reported_not_dropped(): void
    {
        $department = Department::create(['name' => 'Engineering', 'code' => 'ENG']);

        $placed = $this->makeUser('employee');
        EmployeeProfile::factory()->create(['user_id' => $placed->id, 'department_id' => $department->id]);
        $this->file($placed, 'approved', now()->toDateString(), now()->toDateString());

        $stray = $this->makeUser('employee');
        EmployeeProfile::factory()->create(['user_id' => $stray->id, 'department_id' => null]);
        $this->file($stray, 'approved', now()->toDateString(), now()->toDateString());

        $rows = app(DashboardService::class)
            ->applicationsByDepartment(now()->startOfYear(), now()->endOfYear());

        $names = array_column($rows, 'name');
        $this->assertContains('Engineering', $names);
        $this->assertContains('Unassigned', $names,
            'an employee with no department must show as a bar, not vanish from the chart');

        // Per head is the number worth acting on; a department of one that files
        // once is not the same as a department of ten that files once.
        $engineering = collect($rows)->firstWhere('name', 'Engineering');
        $this->assertSame(1, $engineering['staff']);
        $this->assertSame(1.0, $engineering['per_head']);
        $this->assertFalse($engineering['muted']);

        $stray = collect($rows)->firstWhere('name', 'Unassigned');
        $this->assertTrue($stray['muted'], 'the unassigned bar is drawn as a data gap, not a department');
    }

    public function test_the_ranked_leave_types_count_applications_not_days(): void
    {
        $employee = $this->makeUser('employee');
        $sl = LeaveType::where('code', 'SL')->firstOrFail();

        // One long vacation, two short sick leaves. By days VL wins; by
        // applications — which is what was asked for — SL does.
        $this->file($employee, 'approved', now()->startOfMonth()->toDateString(),
            now()->startOfMonth()->addDays(9)->toDateString());
        foreach ([0, 1] as $offset) {
            LeaveRequest::factory()->create([
                'user_id' => $employee->id,
                'leave_type_id' => $sl->id,
                'status' => 'approved',
                'date_filed' => now()->startOfMonth()->addDays($offset)->toDateString(),
                'start_date' => now()->startOfMonth()->addDays($offset)->toDateString(),
                'end_date' => now()->startOfMonth()->addDays($offset)->toDateString(),
            ]);
        }

        $rows = app(DashboardService::class)
            ->mostAppliedTypes(now()->startOfMonth(), now()->endOfMonth());

        $this->assertSame('SL', $rows[0]['label'], 'the axis label is the CSC code');
        $this->assertSame('Sick Leave', $rows[0]['name'], 'the full name is the hover readout');
        $this->assertSame(2, $rows[0]['value']);
    }

    /**
     * The panels are drawn in HTML and inline SVG. No canvas means no Chart.js
     * on this page, and no way to reproduce the runaway-growth bug the Security
     * Dashboard had.
     */
    public function test_the_analytics_need_no_script_and_no_canvas(): void
    {
        $html = $this->visit('system-admin')->assertOk()->getContent();

        $this->assertStringNotContainsString('<canvas', $html);
        $this->assertStringContainsString('<svg class="day-svg"', $html);
        // Outcome is a pie; leave types and departments are bar charts; on
        // leave is a line.
        $this->assertStringContainsString('class="pie-slice', $html);
        $this->assertStringContainsString('class="bar-fill"', $html);
        $this->assertStringContainsString('class="day-stroke"', $html);
        // Every chart carries a table, so none of it is readable by colour alone.
        $this->assertStringContainsString('Show the numbers', $html);
    }

    /** The period switches are radios revealed with :has(), not scripted tabs. */
    public function test_the_period_switches_are_plain_radio_inputs(): void
    {
        $html = $this->visit('system-admin')->assertOk()->getContent();

        foreach (['onleave-today', 'onleave-week', 'onleave-month', 'types-month', 'types-year'] as $id) {
            $this->assertStringContainsString('id="'.$id.'"', $html);
        }

        $css = file_get_contents(public_path('css/app.css'));
        $this->assertStringContainsString('#an-onleave:has(#onleave-week:checked)', $css);
        $this->assertStringContainsString('@supports not selector(:has(*))', $css,
            'a browser without :has() must still be shown one pane, not none');
    }
}
