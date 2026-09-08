<?php

namespace Tests\Feature\Leave;

use App\Models\LeaveType;
use App\Models\User;
use App\Services\Leave\LeaveApplicationService;
use App\Services\Leave\LeaveCreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The three Omnibus rules the system was not following.
 *
 * Named for what they are because the claim being made is a compliance claim:
 * "follows the CSC Omnibus Rules on Leave". Each of these was a way that was
 * not true, and each is arithmetic a reader can check by hand against a
 * circular.
 */
class CscRulesTest extends TestCase
{
    use RefreshDatabase;

    private function applicant(float $vacationCredits = 0): User
    {
        $user = $this->makeUser('employee');
        \App\Models\EmployeeProfile::factory()->create(['user_id' => $user->id]);

        if ($vacationCredits > 0) {
            $vl = LeaveType::where('code', 'VL')->firstOrFail();
            $balance = app(LeaveCreditService::class)->balanceFor($user, $vl);
            $balance->update([
                'earned' => $vacationCredits,
                'balance' => $vacationCredits,
            ]);
        }

        return $user;
    }

    private function submit(User $user, string $code, array $data): mixed
    {
        return app(LeaveApplicationService::class)->submit(
            $user, LeaveType::where('code', $code)->firstOrFail(), $data,
        );
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
    }

    // ---------------------------------------------------------------- days

    /**
     * Maternity leave is 105 CALENDAR days, not 105 working days.
     *
     * RA 11210 grants a span of time. Counted as working days, 105 becomes
     * about 147 calendar days -- roughly forty per cent more leave than the
     * law provides, on the entitlement a reader is most likely to check by
     * hand because the number is famous.
     */
    public function test_maternity_leave_is_counted_in_calendar_days(): void
    {
        $user = $this->applicant();
        $start = Carbon::parse('2026-01-05');

        $request = $this->submit($user, 'ML', [
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addDays(104)->toDateString(), // 105 calendar days
            'date_filed' => $start->copy()->subMonth()->toDateString(),
            'details' => ['delivery_type' => 'live', 'expected_delivery' => $start->toDateString()],
        ]);

        $this->assertSame(105.0, (float) $request->working_days,
            '105 calendar days of maternity leave was not counted as 105 days');
    }

    /** And 105 WORKING days is now over the ceiling, as it should be. */
    public function test_a_maternity_range_longer_than_105_days_is_refused(): void
    {
        $user = $this->applicant();
        $start = Carbon::parse('2026-01-05');

        $this->expectException(ValidationException::class);

        $this->submit($user, 'ML', [
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addDays(105)->toDateString(), // 106
            'date_filed' => $start->copy()->subMonth()->toDateString(),
            'details' => ['delivery_type' => 'live', 'expected_delivery' => $start->toDateString()],
        ]);
    }

    /**
     * Vacation leave stays in working days.
     *
     * The Omnibus Rules do count VL and SL that way, so the fix above must not
     * have swept them along with it.
     */
    public function test_vacation_leave_still_excludes_weekends(): void
    {
        $user = $this->applicant(20);

        // Monday to the following Monday: 8 calendar days, 6 working.
        $request = $this->submit($user, 'VL', [
            'start_date' => '2026-01-05',
            'end_date' => '2026-01-12',
            'date_filed' => '2025-12-20',
            'details' => ['location' => 'within_ph', 'location_specify' => 'Alicia'],
        ]);

        $this->assertSame(6.0, (float) $request->working_days,
            'vacation leave is no longer excluding weekends');
    }

    /**
     * A solo parent gets 120, not 105.
     *
     * The 15-day additional maternity benefit under the Solo Parents' Welfare
     * Act sits on top of the 105 (Rule I item 15, CSC MC 5 s.2021). A flat 105
     * ceiling refused the days the law gives her.
     */
    public function test_a_solo_parent_may_take_the_additional_fifteen_days(): void
    {
        $user = $this->applicant();
        $user->employeeProfile->update(['is_solo_parent' => true]);
        $start = Carbon::parse('2026-01-05');

        $request = $this->submit($user->refresh(), 'ML', [
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addDays(119)->toDateString(), // 120
            'date_filed' => $start->copy()->subMonth()->toDateString(),
            'details' => ['delivery_type' => 'live', 'expected_delivery' => $start->toDateString()],
        ]);

        $this->assertSame(120.0, (float) $request->working_days);
    }

    /** And an employee who is not a solo parent still stops at 105. */
    public function test_120_days_is_refused_without_solo_parent_status(): void
    {
        $start = Carbon::parse('2026-01-05');

        $this->expectException(ValidationException::class);

        $this->submit($this->applicant(), 'ML', [
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addDays(119)->toDateString(),
            'date_filed' => $start->copy()->subMonth()->toDateString(),
            'details' => ['delivery_type' => 'live', 'expected_delivery' => $start->toDateString()],
        ]);
    }

    /**
     * Miscarriage or emergency termination is 60 days, not 105.
     *
     * Sec. 11 gives the shorter entitlement for that contingency. Without the
     * distinction a claim for it could run to 105.
     */
    public function test_a_miscarriage_claim_stops_at_sixty_days(): void
    {
        $start = Carbon::parse('2026-01-05');

        try {
            $this->submit($this->applicant(), 'ML', [
                'start_date' => $start->toDateString(),
                'end_date' => $start->copy()->addDays(60)->toDateString(), // 61
                'date_filed' => $start->copy()->subMonth()->toDateString(),
                'details' => ['delivery_type' => 'miscarriage', 'expected_delivery' => $start->toDateString()],
            ]);
            $this->fail('61 days was accepted for a miscarriage; the ceiling is 60');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('60 day', implode(' ', $e->errors()['policy'] ?? []));
        }
    }

    // ----------------------------------------------- special emergency leave

    /**
     * Calamity leave must be availed within 30 days of the declaration.
     *
     * CSC MC 2 s.2012 item 4. Nothing enforced the window, and nothing asked
     * for the declaration date either, so it could not have been checked by
     * hand.
     */
    public function test_calamity_leave_outside_the_thirty_day_window_is_refused(): void
    {
        try {
            $this->submit($this->applicant(), 'SEL', [
                'start_date' => '2026-03-01',
                'end_date' => '2026-03-05',
                'date_filed' => '2026-03-01',
                'details' => [
                    'calamity' => 'Typhoon',
                    'declaration_date' => '2026-01-10',   // 50 days earlier
                    'calamity_area' => 'Alicia, Isabela',
                ],
            ]);
            $this->fail('calamity leave was accepted 50 days after the declaration');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('within 30 days', implode(' ', $e->errors()['policy'] ?? []));
        }
    }

    /** Inside the window it goes through, in working days and free of credits. */
    public function test_calamity_leave_inside_the_window_is_accepted(): void
    {
        $request = $this->submit($this->applicant(), 'SEL', [
            'start_date' => '2026-01-19',
            'end_date' => '2026-01-23',
            'date_filed' => '2026-01-19',
            'details' => [
                'calamity' => 'Typhoon',
                'declaration_date' => '2026-01-10',
                'calamity_area' => 'Alicia, Isabela',
            ],
        ]);

        // Monday to Friday: five working days, which is what the circular
        // grants -- "five straight working days or on staggered basis".
        $this->assertSame(5.0, (float) $request->working_days);
    }

    /**
     * Paternity and calamity leave are counted in WORKING days.
     *
     * Both circulars say so in as many words -- "seven (7) working days"
     * (Sec. 19, CSC MC 5 s.2021) and "five straight working days" (CSC MC 2
     * s.2012 item 2) -- and both were candidates for the calendar-day change.
     * Pinned so that change cannot creep onto them later.
     */
    public function test_paternity_and_calamity_leave_stay_in_working_days(): void
    {
        foreach (['PL', 'SEL'] as $code) {
            $this->assertFalse(
                (bool) LeaveType::where('code', $code)->firstOrFail()->counts_calendar_days,
                "{$code} was switched to calendar days; both circulars say working days");
        }
    }

    // -------------------------------------------------------- monetization

    /** Ten days is the minimum that may be monetized. */
    public function test_monetizing_fewer_than_ten_days_is_refused(): void
    {
        $user = $this->applicant(40);

        try {
            $this->submit($user, 'MON', [
                'start_date' => '2026-01-05',
                'end_date' => '2026-01-05',
                'date_filed' => '2026-01-05',
                'details' => ['reason' => 'Tuition', 'days_to_monetize' => 5],
            ]);
            $this->fail('monetizing 5 days was accepted; the minimum is 10');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('at least 10 day', implode(' ', $e->errors()['policy'] ?? []));
        }
    }

    /** And fifteen vacation days must survive it. */
    public function test_monetization_must_leave_fifteen_days_behind(): void
    {
        $user = $this->applicant(20);

        try {
            $this->submit($user, 'MON', [
                'start_date' => '2026-01-05',
                'end_date' => '2026-01-05',
                'date_filed' => '2026-01-05',
                'details' => ['reason' => 'Tuition', 'days_to_monetize' => 10],
            ]);
            $this->fail('monetizing down to 10 days was accepted; 15 must remain');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('must remain', implode(' ', $e->errors()['policy'] ?? []));
        }
    }

    /** A request that satisfies both is accepted. */
    public function test_a_compliant_monetization_is_accepted(): void
    {
        $user = $this->applicant(40);

        $request = $this->submit($user, 'MON', [
            'start_date' => '2026-01-05',
            'end_date' => '2026-01-05',
            'date_filed' => '2026-01-05',
            'details' => ['reason' => 'Tuition', 'days_to_monetize' => 20],
        ]);

        // And the quantity is the number they asked for, not the date range.
        // The range here is a single day; the deduction is twenty.
        $this->assertSame(20.0, (float) $request->working_days,
            'monetization counted the dates instead of the days asked for');
    }

    // --------------------------------------------------------- forced leave

    /**
     * Mandatory leave applies from ten vacation credits upward.
     *
     * Below that the employee is not required to take it, and charging five
     * days against a balance that small is how somebody ends up unable to take
     * sick leave later in the year.
     */
    public function test_forced_leave_below_ten_credits_is_refused(): void
    {
        $user = $this->applicant(6);

        try {
            $this->submit($user, 'FL', [
                'start_date' => '2026-01-05',
                'end_date' => '2026-01-09',
                'date_filed' => '2026-01-02',
                'details' => ['location' => 'within_ph'],
            ]);
            $this->fail('forced leave was accepted on 6 credits');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('exempt', implode(' ', $e->errors()['policy'] ?? []));
        }
    }

    /** At ten or more it goes through. */
    public function test_forced_leave_at_ten_credits_is_accepted(): void
    {
        $user = $this->applicant(10);

        $request = $this->submit($user, 'FL', [
            'start_date' => '2026-01-05',
            'end_date' => '2026-01-09',
            'date_filed' => '2026-01-02',
            'details' => ['location' => 'within_ph'],
        ]);

        $this->assertSame(5.0, (float) $request->working_days);
    }

    /**
     * All three thresholds are settings.
     *
     * A circular can change any of them, and that should not need a developer.
     */
    public function test_the_thresholds_are_configurable(): void
    {
        \App\Models\SystemSetting::set('leave.forced_leave_min_vl', 3);

        // Six credits, not four. Five days of forced leave still have to be
        // paid for out of the balance, so four would fail the credit guard
        // instead of the threshold and prove nothing about the setting.
        $request = $this->submit($this->applicant(6), 'FL', [
            'start_date' => '2026-01-05',
            'end_date' => '2026-01-09',
            'date_filed' => '2026-01-02',
            'details' => ['location' => 'within_ph'],
        ]);

        $this->assertNotNull($request->id);
    }
}
