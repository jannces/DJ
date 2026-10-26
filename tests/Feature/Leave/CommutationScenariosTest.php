<?php

namespace Tests\Feature\Leave;

use App\Models\Department;
use App\Models\EmployeeProfile;
use App\Models\LeaveBalance;
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
 * The four acceptance scenarios for monetization and terminal leave.
 *
 *   24  Monetization            10 days against VL 30.000 → VL 20.000, money value
 *   25  Monetization refused    10 days that would breach the 15-day retention
 *   26  Terminal leave          VL 120.500 + SL 150.250 → 270.750 × salary × CF
 *   27  Terminal, fractional    VL 45.375 + SL 60.125 → exactly 105.500
 *
 * 26 and 27 are the reason leave_requests.working_days was widened from
 * decimal(5,1) to decimal(8,3). The balances they are summed from have always
 * held three decimals; the request did not, so 270.750 was stored as 270.8 and
 * the payout came out ₱72.29 heavy on a ₱30,000 salary. A retirement benefit
 * that disagrees with the accountant's own arithmetic is the one figure in
 * this system that cannot be approximate.
 */
class CommutationScenariosTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();

        $this->employee = $this->makeUser('employee');
        EmployeeProfile::factory()->create([
            'user_id' => $this->employee->id,
            'department_id' => Department::create(['name' => 'Municipal Treasury Office', 'code' => 'MTO'])->id,
            'position_id' => Position::factory()->create()->id,
            'salary' => 30000,
        ]);
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

    /** @param array<string,mixed> $details */
    private function submit(string $code, array $details): LeaveRequest
    {
        return app(LeaveApplicationService::class)->submit(
            $this->employee,
            LeaveType::where('code', $code)->firstOrFail(),
            [
                'date_filed' => '2026-11-20',
                'start_date' => '2026-12-01',
                'end_date' => '2026-12-10',
                'applicant_signature' => $this->employee->name,
                'details' => $details,
            ],
        );
    }

    // ------------------------------------------------------------ scenario 24

    /**
     * 10 days monetized against a vacation balance of 30.000.
     *
     * Allowed: at least the 10-day minimum, and 30 − 10 = 20 leaves more than
     * the 15 days that must remain. The balance falls to 20.000 and the money
     * value is the CSC formula on 10 days.
     */
    public function test_scenario_24_monetization_of_ten_days(): void
    {
        $this->credit('VL', 30.000);

        $request = $this->submit('MON', ['reason' => 'Tuition', 'days_to_monetize' => 10]);
        app(LeaveCreditService::class)->deductForApproval($request->fresh());

        $this->assertSame(10.0, (float) $request->working_days,
            'the day count came from the date range instead of the field');
        $this->assertSame(20.0, $this->balance('VL'));

        // 30,000 × 10 × 0.0481927
        $this->assertSame(14457.81, app(LeaveMoneyValue::class)->compute(10, 30000));
    }

    // ------------------------------------------------------------ scenario 25

    /**
     * The same 10 days against a balance that cannot spare them.
     *
     * 20 − 10 = 10, below the 15 vacation days the CSC rules require to
     * remain, so the application is refused and nothing is deducted.
     */
    public function test_scenario_25_monetization_below_the_retention_floor(): void
    {
        $this->credit('VL', 20.000);

        try {
            $this->submit('MON', ['reason' => 'Tuition', 'days_to_monetize' => 10]);
            $this->fail('monetization was accepted although it breaches the retention floor');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('must remain after monetizing',
                implode(' ', $e->validator->errors()->all()));
        }

        $this->assertSame(20.0, $this->balance('VL'), 'credits were deducted by a refused application');
        $this->assertSame(0, LeaveRequest::count());
    }

    /** And the other floor: fewer than 10 days at a time is refused too. */
    public function test_scenario_25b_monetization_below_the_minimum(): void
    {
        $this->credit('VL', 40.000);

        try {
            $this->submit('MON', ['reason' => 'Tuition', 'days_to_monetize' => 5]);
            $this->fail('monetization of fewer than the minimum days was accepted');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('at least', implode(' ', $e->validator->errors()->all()));
        }

        $this->assertSame(40.0, $this->balance('VL'));
    }

    // ------------------------------------------------------------ scenario 26

    /**
     * A retiring employee: VL 120.500 + SL 150.250 = 270.750 credits.
     *
     * The assertion that matters is the exact 270.750. Under decimal(5,1) it
     * was stored as 270.8, and the benefit computed from it was wrong by
     * 30,000 × 0.05 × 0.0481927 = ₱72.29.
     */
    public function test_scenario_26_terminal_leave_sums_both_balances_exactly(): void
    {
        $this->credit('VL', 120.500);
        $this->credit('SL', 150.250);

        $request = $this->submit('TL', ['separation_type' => 'retirement']);

        $this->assertSame(270.750, (float) $request->working_days,
            'the credit total was rounded on the way into the request');

        // 30,000 × 270.750 × 0.0481927 = 391,445.21
        $expected = round(30000 * 270.750 * LeaveMoneyValue::CONSTANT_FACTOR, 2);
        $this->assertSame($expected, app(LeaveMoneyValue::class)->compute(270.750, 30000));
        $this->assertSame(391445.21, $expected);
    }

    /** It survives the round trip to the database, not just the in-memory sum. */
    public function test_scenario_26_the_stored_total_is_not_rounded(): void
    {
        $this->credit('VL', 120.500);
        $this->credit('SL', 150.250);

        $id = $this->submit('TL', ['separation_type' => 'retirement'])->id;

        $this->assertSame(270.750, (float) LeaveRequest::findOrFail($id)->working_days,
            'the request reads back rounded, so the column or the cast still truncates');
    }

    /** And the page shows the benefit computed from the unrounded total. */
    public function test_scenario_26_the_page_shows_the_full_benefit(): void
    {
        $this->credit('VL', 120.500);
        $this->credit('SL', 150.250);

        $request = $this->submit('TL', ['separation_type' => 'retirement']);

        $this->actingAs($this->employee);
        session(['otp_verified' => true]);

        $this->get("/leave/{$request->id}")
            ->assertOk()
            ->assertSee('391,445.21')
            ->assertSee('270.75');
    }

    // ------------------------------------------------------------ scenario 27

    /**
     * Fractional credits that must add up exactly: 45.375 + 60.125 = 105.500.
     *
     * Asserted against the string as well as the float, because a third
     * decimal lost to the column would still compare equal as a float if it
     * happened to round to the same value -- and here it would not, but the
     * test should not depend on that luck.
     */
    public function test_scenario_27_fractional_credits_add_up_exactly(): void
    {
        $this->credit('VL', 45.375);
        $this->credit('SL', 60.125);

        $request = $this->submit('TL', ['separation_type' => 'retirement'])->fresh();

        $this->assertSame(105.500, (float) $request->working_days);
        $this->assertSame('105.500', (string) $request->working_days,
            'the stored value lost a decimal place');
    }

    /** Both balances end at zero, with nothing left over from the fractions. */
    public function test_scenario_27_leaves_no_credit_behind(): void
    {
        $this->credit('VL', 45.375);
        $this->credit('SL', 60.125);

        $request = $this->submit('TL', ['separation_type' => 'retirement']);
        app(LeaveCreditService::class)->deductForApproval($request->fresh());

        $this->assertSame(0.0, $this->balance('VL'));
        $this->assertSame(0.0, $this->balance('SL'));
    }

    /** The money value of those fractions is exact too. */
    public function test_scenario_27_money_value_has_no_rounding_error(): void
    {
        // 30,000 × 105.500 × 0.0481927 = 152,529.90 (to centavos)
        $this->assertSame(
            round(30000 * 105.500 * LeaveMoneyValue::CONSTANT_FACTOR, 2),
            app(LeaveMoneyValue::class)->compute(105.500, 30000),
        );
    }
}
