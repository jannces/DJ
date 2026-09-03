<?php

namespace Tests\Feature\Dashboard;

use App\Models\Department;
use App\Models\EmployeeProfile;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The three cards added to the leave management pane.
 *
 * Each answers something the counters above them cannot. The counter says seven
 * are waiting; the queue says which seven. Nothing else on the page looks
 * forward; coverage risk does. And nothing at all tracked Mandatory Leave,
 * which the CSC requires five days of a year and which does not carry over.
 *
 * Every column they read already existed. No new tables, no new permissions.
 */
class ManagementPaneTest extends TestCase
{
    use RefreshDatabase;

    private LeaveType $vl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->vl = LeaveType::where('code', 'VL')->firstOrFail();
    }

    private function service(): DashboardService
    {
        return app(DashboardService::class);
    }

    private function employeeIn(?Department $department, string $last = 'Reyes', string $first = 'M'): User
    {
        $user = $this->makeUser('employee');
        EmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'department_id' => $department?->id,
            'last_name' => $last,
            'first_name' => $first,
        ]);

        return $user;
    }

    private function leave(User $user, string $status, string $start, string $end, ?string $filed = null): LeaveRequest
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

    // ------------------------------------------------------- the waiting queue

    public function test_the_queue_names_the_applications_the_counter_counts(): void
    {
        $employee = $this->employeeIn(null, 'Bautista', 'Rosa');

        $this->leave($employee, 'pending', now()->addDays(3)->toDateString(),
            now()->addDays(4)->toDateString(), now()->subDays(9)->toDateString());
        $this->leave($employee, 'hr_review', now()->addDays(5)->toDateString(),
            now()->addDays(5)->toDateString(), now()->subDay()->toDateString());
        // Decided: not waiting on anybody.
        $this->leave($employee, 'approved', now()->toDateString(), now()->toDateString());

        $queue = $this->service()->waitingQueue();

        $this->assertSame(2, $queue['total'], 'only the open statuses are waiting');
        $this->assertSame(1, $queue['stale'], 'one has been waiting more than five days');

        // Oldest first: an officer starting at the top starts with the worst.
        $this->assertSame(9, $queue['rows'][0]['age']);
        $this->assertTrue($queue['rows'][0]['stale']);
        $this->assertSame('Bautista, Rosa', $queue['rows'][0]['who']);
        $this->assertFalse($queue['rows'][1]['stale']);
    }

    /**
     * An application filed by somebody with no employee record still has to
     * appear. It is a data problem, and dropping the row hides it.
     */
    public function test_an_applicant_with_no_profile_is_still_named(): void
    {
        $user = $this->makeUser('employee');
        $this->leave($user, 'pending', now()->toDateString(), now()->toDateString());

        $rows = $this->service()->waitingQueue()['rows'];

        $this->assertCount(1, $rows);
        $this->assertNotSame('', trim($rows[0]['who']));
    }

    /** The median, not the mean: one forgotten application must not skew it. */
    public function test_the_decision_time_is_a_median(): void
    {
        $employee = $this->employeeIn(null);

        // All four inside this month, so all four are counted; the point is
        // that the last one is far enough out to wreck a mean.
        foreach ([1, 2, 3, 20] as $days) {
            LeaveRequest::factory()->create([
                'user_id' => $employee->id,
                'leave_type_id' => $this->vl->id,
                'status' => 'approved',
                'date_filed' => now()->startOfMonth()->toDateString(),
                'start_date' => now()->startOfMonth()->toDateString(),
                'end_date' => now()->startOfMonth()->toDateString(),
                'decided_at' => now()->startOfMonth()->addDays($days),
            ]);
        }

        $decided = $this->service()->decisionsThisMonth();

        $this->assertSame(4, $decided['count']);
        $this->assertSame(2.5, $decided['median'], 'a single 20-day outlier must not move this');
    }

    // -------------------------------------------------------- coverage risk

    public function test_coverage_risk_reports_the_worst_single_day_per_office(): void
    {
        $treasury = Department::create(['name' => 'Municipal Treasury Office', 'code' => 'MTO']);
        $health = Department::create(['name' => 'Municipal Health Office', 'code' => 'MHO']);

        // Six in Treasury, three of them away on the same two days.
        $away = [];
        for ($i = 0; $i < 6; $i++) {
            $away[] = $this->employeeIn($treasury, 'T'.$i);
        }
        foreach (array_slice($away, 0, 3) as $employee) {
            $this->leave($employee, 'approved',
                now()->addDays(2)->toDateString(), now()->addDays(3)->toDateString());
        }

        // Health: five staff, one away, on a different day.
        for ($i = 0; $i < 5; $i++) {
            $staff = $this->employeeIn($health, 'H'.$i);
            if ($i === 0) {
                $this->leave($staff, 'approved',
                    now()->addDays(5)->toDateString(), now()->addDays(5)->toDateString());
            }
        }

        $rows = collect($this->service()->coverageRisk());
        $mto = $rows->firstWhere('office', 'Municipal Treasury Office');
        $mho = $rows->firstWhere('office', 'Municipal Health Office');

        $this->assertSame(3, $mto['out'], 'three away on the same day is three, not six day-absences');
        $this->assertSame(6, $mto['staff']);
        $this->assertSame(50, $mto['pct']);
        $this->assertTrue($mto['at_risk'], 'half an office away at once is a risk');

        $this->assertSame(1, $mho['out']);
        $this->assertFalse($mho['at_risk']);

        // Worst first, so the office that cannot function is at the top.
        $this->assertSame('Municipal Treasury Office', $rows->first()['office']);
    }

    /** Leave that has already ended is not a staffing problem. */
    public function test_coverage_risk_looks_forward_only(): void
    {
        $office = Department::create(['name' => 'Mayor\'s Office', 'code' => 'MO']);
        $employee = $this->employeeIn($office);

        $this->leave($employee, 'approved',
            now()->subDays(10)->toDateString(), now()->subDays(8)->toDateString());

        $row = collect($this->service()->coverageRisk())->firstWhere('office', 'Mayor\'s Office');

        $this->assertSame(0, $row['out']);
        $this->assertNull($row['when']);
    }

    /** Pending leave has not been granted, so it cannot be counted as absence. */
    public function test_only_approved_leave_counts_against_coverage(): void
    {
        $office = Department::create(['name' => 'Municipal Engineering Office', 'code' => 'MEO']);
        $employee = $this->employeeIn($office);

        $this->leave($employee, 'pending',
            now()->addDay()->toDateString(), now()->addDay()->toDateString());

        $row = collect($this->service()->coverageRisk())->firstWhere('office', 'Municipal Engineering Office');
        $this->assertSame(0, $row['out']);
    }

    // ---------------------------------------------------- mandatory leave

    public function test_mandatory_leave_counts_only_those_who_have_filed_none(): void
    {
        $office = Department::create(['name' => 'Municipal Health Office', 'code' => 'MHO']);
        $fl = LeaveType::where('code', 'FL')->firstOrFail();

        $filed = $this->employeeIn($office, 'Filed');
        $notFiled = $this->employeeIn($office, 'NotFiled');
        // Somebody the credits never accrued for is not out of compliance.
        $noCredits = $this->employeeIn($office, 'NoCredits');

        LeaveBalance::create(['user_id' => $filed->id, 'leave_type_id' => $fl->id,
            'earned' => 5, 'used' => 5, 'balance' => 0]);
        LeaveBalance::create(['user_id' => $notFiled->id, 'leave_type_id' => $fl->id,
            'earned' => 5, 'used' => 0, 'balance' => 5]);
        LeaveBalance::create(['user_id' => $noCredits->id, 'leave_type_id' => $fl->id,
            'earned' => 0, 'used' => 0, 'balance' => 0]);

        $compliance = $this->service()->mandatoryLeaveCompliance();

        $this->assertTrue($compliance['tracked']);
        $this->assertSame(1, $compliance['outstanding']);
        $this->assertSame(13 - (int) now()->month, $compliance['months_left']);

        $mho = collect($compliance['by_office'])->firstWhere('label', 'Municipal Health Office');
        $this->assertSame(1, $mho['value']);
    }

    /** Every office appears, including the ones with nobody outstanding. */
    public function test_every_office_appears_even_at_zero(): void
    {
        Department::create(['name' => 'Municipal Treasury Office', 'code' => 'MTO']);
        Department::create(['name' => 'Mayor\'s Office', 'code' => 'MO']);

        $rows = $this->service()->mandatoryLeaveCompliance()['by_office'];

        $this->assertCount(2, $rows);
        $this->assertSame([0, 0], array_column($rows, 'value'));
    }

    // ------------------------------------------------------------ on screen

    /**
     * The three cards are OFF the dashboard.
     *
     * Waiting longest, Coverage risk and Mandatory Leave not yet filed were
     * removed from HR's pane. The service methods behind them are still tested
     * above, and still called by the reports -- what changed is that the
     * dashboard no longer carries them.
     */
    public function test_the_three_cards_are_no_longer_on_the_dashboard(): void
    {
        $this->actingAs($this->makeUser('hr'));
        session(['otp_verified' => true]);

        $this->get('/dashboard')->assertOk()
            ->assertDontSee('Waiting longest')
            ->assertDontSee('Coverage risk')
            ->assertDontSee('Mandatory Leave not yet filed');
    }
}
