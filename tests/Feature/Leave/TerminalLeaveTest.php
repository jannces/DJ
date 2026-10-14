<?php

namespace Tests\Feature\Leave;

use App\Models\Department;
use App\Models\EmployeeProfile;
use App\Models\LeaveBalance;
use App\Models\LeaveHistory;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\User;
use App\Services\Leave\LeaveApplicationService;
use App\Services\Leave\LeaveCreditService;
use App\Services\Leave\LeaveMoneyValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Terminal leave pays the money value of every accumulated credit.
 *
 *     money value = highest monthly salary × credit days × 0.0481927
 *
 * and the credit days are read off the ledger, not chosen. The employee has
 * left the service; there is no period of absence to pick dates for.
 *
 * It used to go down the ordinary path: working days counted between From and
 * To, checked against the VACATION balance alone, and that many vacation days
 * deducted. So a retiree with 92.5 VL and 57.5 SL who typed a five-day span
 * had five vacation days deducted and the other 145 credits left sitting
 * unpaid -- the payout decided by which dates happened to be typed, and
 * nothing anywhere to notice. Somebody whose credits were mostly sick leave
 * was told they had insufficient credits for a benefit they were owed in full.
 */
class TerminalLeaveTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;

    private LeaveType $tl;

    private Department $office;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();

        $this->office = Department::create(['name' => 'Municipal Treasury Office', 'code' => 'MTO']);
        $this->tl = LeaveType::where('code', 'TL')->firstOrFail();

        $this->employee = $this->makeUser('employee');
        EmployeeProfile::factory()->create([
            'user_id' => $this->employee->id,
            'department_id' => $this->office->id,
            'position_id' => Position::factory()->create()->id,
            'salary' => 30000,
        ]);

        $this->credit('VL', 92.5);
        $this->credit('SL', 57.5);
    }

    private function credit(string $code, float $days): void
    {
        $type = LeaveType::where('code', $code)->firstOrFail();

        LeaveBalance::updateOrCreate(
            ['user_id' => $this->employee->id, 'leave_type_id' => $type->id],
            ['earned' => $days, 'used' => 0, 'balance' => $days],
        );
    }

    private function balance(string $code): float
    {
        $type = LeaveType::where('code', $code)->firstOrFail();

        return (float) LeaveBalance::where('user_id', $this->employee->id)
            ->where('leave_type_id', $type->id)->value('balance');
    }

    /** Files terminal leave with a deliberately short date range. */
    private function file(string $from = '2026-12-01', string $to = '2026-12-05'): LeaveRequest
    {
        return app(LeaveApplicationService::class)->submit($this->employee, $this->tl, [
            'date_filed' => '2026-11-20',
            'start_date' => $from,
            'end_date' => $to,
            'applicant_signature' => $this->employee->name,
            'details' => ['separation_type' => 'retirement'],
        ]);
    }

    // ------------------------------------------------------- the credit count

    /**
     * Every credit, vacation and sick, regardless of the dates.
     *
     * The five-day range in file() is the whole point: it must not matter.
     */
    public function test_the_days_are_all_accumulated_credits_not_the_date_range(): void
    {
        $request = $this->file();

        $this->assertSame(150.0, (float) $request->working_days,
            'the payout was taken from the date range instead of the ledger');
    }

    /** A different range gives the same answer. */
    public function test_the_date_range_does_not_change_the_credit_count(): void
    {
        $short = $this->file('2026-12-01', '2026-12-01');

        $this->assertSame(150.0, (float) $short->working_days);
    }

    /**
     * Sick leave counts.
     *
     * Under the old path this employee could not file at all: 150 days checked
     * against a vacation balance of 0.
     */
    public function test_an_employee_whose_credits_are_all_sick_leave_can_still_file(): void
    {
        $this->credit('VL', 0);
        $this->credit('SL', 120);

        $this->assertSame(120.0, (float) $this->file()->working_days);
    }

    /** With nothing accumulated there is nothing to commute. */
    public function test_filing_with_no_credits_is_refused(): void
    {
        $this->credit('VL', 0);
        $this->credit('SL', 0);

        $this->expectException(ValidationException::class);
        $this->file();
    }

    /**
     * There is no period of absence, so the two dates are the same day.
     *
     * The date the application carries is the effectivity of the separation.
     */
    public function test_the_end_date_is_the_effectivity_date(): void
    {
        $request = $this->file('2026-12-01', '2026-12-31');

        $this->assertSame('2026-12-01', $request->start_date->toDateString());
        $this->assertSame('2026-12-01', $request->end_date->toDateString(),
            'terminal leave was given a span of days to be absent for');
    }

    // ----------------------------------------------------------- the ledger

    /** Approval empties both balances, not one. */
    public function test_approval_commutes_both_balances(): void
    {
        $request = $this->file();
        app(LeaveCreditService::class)->deductForApproval($request->fresh());

        $this->assertSame(0.0, $this->balance('VL'));
        $this->assertSame(0.0, $this->balance('SL'),
            'the sick leave credits were not commuted and are still sitting there');
    }

    /**
     * One ledger row per balance, so the split is on the record.
     *
     * An accountant has to be able to reconstruct the payment: 92.5 vacation
     * and 57.5 sick, not an undifferentiated 150.
     */
    public function test_the_ledger_records_each_balance_separately(): void
    {
        $request = $this->file();
        app(LeaveCreditService::class)->deductForApproval($request->fresh());

        $rows = LeaveHistory::where('leave_request_id', $request->id)
            ->where('entry_type', 'deduction')->get();

        $this->assertCount(2, $rows, 'the commutation was not broken down by credit type');
        $this->assertEqualsCanonicalizing([-92.5, -57.5],
            $rows->map(fn ($r) => (float) $r->days)->all());
    }

    /** Deducting twice does not empty an already-empty balance again. */
    public function test_the_deduction_is_idempotent(): void
    {
        $request = $this->file();
        $credits = app(LeaveCreditService::class);

        $credits->deductForApproval($request->fresh());
        $credits->deductForApproval($request->fresh());

        $this->assertCount(2, LeaveHistory::where('leave_request_id', $request->id)
            ->where('entry_type', 'deduction')->get());
    }

    /**
     * Cancelling gives back BOTH balances.
     *
     * reverseDeduction read only the first deduction row, which would have
     * returned the vacation credits and kept the sick ones spent.
     */
    public function test_reversing_restores_both_balances(): void
    {
        $request = $this->file();
        $credits = app(LeaveCreditService::class);

        $credits->deductForApproval($request->fresh());
        $credits->reverseDeduction($request->fresh());

        $this->assertSame(92.5, $this->balance('VL'));
        $this->assertSame(57.5, $this->balance('SL'),
            'the sick leave credits were not given back');
    }

    // ------------------------------------------------------- the money value

    /**
     * The worked example, as an arithmetic fixture.
     *
     *     ₱30,000 × 150 days × 0.0481927 = ₱216,867.15
     */
    public function test_the_money_value_follows_the_csc_formula(): void
    {
        $this->assertSame(216867.15, app(LeaveMoneyValue::class)->compute(150, 30000));
    }

    /**
     * The constant is the published, truncated one.
     *
     * 1 ÷ 20.75 rounds to 0.0481928. The issuances publish 0.0481927, and an
     * LGU accountant checking a payout by hand uses that -- so a system using
     * the arithmetically closer figure disagrees with them by centavos on
     * every computation.
     */
    public function test_the_constant_factor_is_the_published_figure(): void
    {
        $this->assertSame(0.0481927, LeaveMoneyValue::CONSTANT_FACTOR);
        $this->assertNotSame(round(1 / LeaveMoneyValue::WORKING_DAYS_PER_MONTH, 7),
            LeaveMoneyValue::CONSTANT_FACTOR,
            'the constant is being computed rather than taken from the issuance');
    }

    /** A factor of zero would value every retirement at nothing. */
    public function test_a_broken_factor_falls_back_to_the_constant(): void
    {
        \App\Models\SystemSetting::set('leave.money_value_factor', '0');

        $this->assertSame(LeaveMoneyValue::CONSTANT_FACTOR, app(LeaveMoneyValue::class)->factor());
    }

    // ------------------------------------------------------------ the screen

    /** The page shows the figure and the working behind it. */
    public function test_the_request_page_shows_the_money_value(): void
    {
        $request = $this->file();

        $this->actingAs($this->employee);
        session(['otp_verified' => true]);

        $this->get("/leave/{$request->id}")
            ->assertOk()
            ->assertSee('Money value')
            ->assertSee('216,867.15')
            ->assertSee('30,000.00')
            ->assertSee('0.0481927');
    }

    /** An ordinary vacation has no money value and is not given one. */
    public function test_an_ordinary_leave_has_no_money_panel(): void
    {
        $vl = LeaveType::where('code', 'VL')->firstOrFail();

        $request = app(LeaveApplicationService::class)->submit($this->employee, $vl, [
            'date_filed' => '2026-11-20',
            'start_date' => '2026-12-07',
            'end_date' => '2026-12-08',
            'applicant_signature' => $this->employee->name,
            'details' => ['location' => 'within_ph', 'location_specify' => 'Alicia'],
        ]);

        $this->actingAs($this->employee);
        session(['otp_verified' => true]);

        $this->get("/leave/{$request->id}")->assertOk()->assertDontSee('Money value');
    }

    // ----------------------------------------------------------- commutation

    /**
     * Terminal leave IS commutation, and the form must stop saying otherwise.
     *
     * The column defaults to false and the entry form no longer asks, so box
     * 6.D printed "Not Requested" on every retirement payout -- the official
     * sheet stating the opposite of what it was filed for.
     */
    public function test_terminal_leave_is_recorded_as_commutation(): void
    {
        $this->assertTrue((bool) $this->file()->commutation);
    }

    /** Monetization is commutation too. */
    public function test_monetization_is_recorded_as_commutation(): void
    {
        $mon = LeaveType::where('code', 'MON')->firstOrFail();

        $request = app(LeaveApplicationService::class)->submit($this->employee, $mon, [
            'date_filed' => '2026-11-20',
            'start_date' => '2026-12-01',
            'end_date' => '2026-12-10',
            'applicant_signature' => $this->employee->name,
            'details' => ['reason' => 'Tuition', 'days_to_monetize' => 20],
        ]);

        $this->assertTrue((bool) $request->commutation);
        $this->assertSame(20.0, (float) $request->working_days,
            'monetization took its day count from the date range again');
    }

    /** An ordinary leave is untouched by that rule. */
    public function test_an_ordinary_leave_does_not_become_commutation(): void
    {
        $vl = LeaveType::where('code', 'VL')->firstOrFail();

        $request = app(LeaveApplicationService::class)->submit($this->employee, $vl, [
            'date_filed' => '2026-11-20',
            'start_date' => '2026-12-07',
            'end_date' => '2026-12-08',
            'applicant_signature' => $this->employee->name,
            'details' => ['location' => 'within_ph', 'location_specify' => 'Alicia'],
        ]);

        $this->assertFalse((bool) $request->commutation);
    }
}
