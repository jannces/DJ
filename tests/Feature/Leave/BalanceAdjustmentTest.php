<?php

namespace Tests\Feature\Leave;

use App\Models\Department;
use App\Models\EmployeeProfile;
use App\Models\LeaveBalance;
use App\Models\LeaveHistory;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Adjusting an employee's leave credits.
 *
 * The form took one leave type per submit, so correcting both Vacation and
 * Sick meant applying, waiting for the page, opening the dialog again and
 * writing the reason a second time. It takes all of them at once now, and
 * these hold the parts of that which are easy to get wrong: that blank rows
 * do nothing, that the whole set commits or none of it does, and that a
 * refusal comes back as a message rather than as a 500.
 */
class BalanceAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    private User $hr;

    private User $employee;

    private LeaveType $vl;

    private LeaveType $sl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();

        $this->vl = LeaveType::where('code', 'VL')->firstOrFail();
        $this->sl = LeaveType::where('code', 'SL')->firstOrFail();

        $office = Department::create(['name' => 'Municipal Treasurers Office', 'code' => 'MTO']);
        $this->employee = $this->makeUser('employee');
        $this->employee->update(['name' => 'Arvie O. Villanueva']);
        EmployeeProfile::factory()->create([
            'user_id' => $this->employee->id, 'employee_no' => 'EMP-0001',
            'department_id' => $office->id, 'position_id' => Position::factory()->create()->id,
        ]);

        foreach ([[$this->vl, 25.0], [$this->sl, 10.0]] as [$type, $balance]) {
            LeaveBalance::create([
                'user_id' => $this->employee->id, 'leave_type_id' => $type->id,
                'earned' => $balance, 'used' => 0, 'balance' => $balance,
            ]);
        }

        $this->hr = $this->makeUser('hr');
    }

    private function asHr(): self
    {
        $this->actingAs($this->hr);
        session(['otp_verified' => true]);

        return $this;
    }

    private function balance(LeaveType $type): float
    {
        return (float) LeaveBalance::where('user_id', $this->employee->id)
            ->where('leave_type_id', $type->id)->value('balance');
    }

    /** The whole point: two types, one submit. */
    public function test_both_balances_move_in_one_submission(): void
    {
        $this->asHr()->post(route('balances.adjust', $this->employee), [
            'days' => [$this->vl->id => 5, $this->sl->id => -3],
            'remarks' => 'Carry-over from 2025',
        ])->assertRedirect();

        $this->assertSame(30.0, $this->balance($this->vl));
        $this->assertSame(7.0, $this->balance($this->sl));

        // One reason, written once, recorded against both.
        $this->assertSame(2, LeaveHistory::where('user_id', $this->employee->id)
            ->where('entry_type', 'adjustment')->count());
    }

    /** A blank box is not a zero: it means "leave this one alone". */
    public function test_a_blank_row_changes_nothing(): void
    {
        $this->asHr()->post(route('balances.adjust', $this->employee), [
            'days' => [$this->vl->id => 2, $this->sl->id => ''],
            'remarks' => 'Vacation only',
        ])->assertRedirect();

        $this->assertSame(27.0, $this->balance($this->vl));
        $this->assertSame(10.0, $this->balance($this->sl));
        $this->assertSame(1, LeaveHistory::where('user_id', $this->employee->id)
            ->where('entry_type', 'adjustment')->count());
    }

    /** Submitting nothing at all is a mistake worth naming. */
    public function test_an_empty_form_is_refused_with_a_message(): void
    {
        $this->asHr()->post(route('balances.adjust', $this->employee), [
            'days' => [$this->vl->id => '', $this->sl->id => ''],
            'remarks' => 'Nothing to see',
        ])
            ->assertRedirect()
            ->assertSessionHasErrors('days');

        $this->assertSame(25.0, $this->balance($this->vl));
    }

    /**
     * An adjustment that would go below zero is refused, and says so.
     *
     * The service throws for this and the controller did not catch it, so the
     * officer got a 500 page: no message, and everything they had typed gone.
     */
    public function test_an_over_deduction_comes_back_as_a_message_not_a_500(): void
    {
        $this->asHr()->post(route('balances.adjust', $this->employee), [
            'days' => [$this->sl->id => -50],
            'remarks' => 'Too much',
        ])
            ->assertRedirect()
            ->assertSessionHasErrors('days');

        $this->assertSame(10.0, $this->balance($this->sl));
    }

    /**
     * All of it, or none of it.
     *
     * Two types in two transactions means a failure on the second leaves the
     * first applied, and the officer is reading a page that disagrees with the
     * ledger. The valid Vacation change must roll back with the refused Sick
     * one.
     */
    public function test_a_refusal_rolls_back_the_whole_set(): void
    {
        $this->asHr()->post(route('balances.adjust', $this->employee), [
            'days' => [$this->vl->id => 5, $this->sl->id => -99],
            'remarks' => 'One good, one impossible',
        ])->assertSessionHasErrors('days');

        $this->assertSame(25.0, $this->balance($this->vl),
            'the vacation adjustment survived a failure on the sick one');
        $this->assertSame(10.0, $this->balance($this->sl));
        $this->assertSame(0, LeaveHistory::where('user_id', $this->employee->id)
            ->where('entry_type', 'adjustment')->count());
    }

    /**
     * A refusal names the leave type, and says what a workable number is.
     *
     * Five rows are on screen. "The balance would go negative" identifies
     * none of them, and does not say what the balance actually is -- so the
     * officer leaves to go and look it up, which is the round trip this
     * dialog exists to save.
     */
    public function test_a_refusal_names_the_type_and_the_figure(): void
    {
        $this->asHr()->from(route('balances.index'))
            ->post(route('balances.adjust', $this->employee), [
                'days' => [$this->sl->id => -50],
                'remarks' => 'Too much',
            ]);

        $message = session('errors')->first('days');

        $this->assertStringContainsString('Sick Leave', $message);
        $this->assertStringContainsString('50.00', $message, 'the refusal does not repeat what was asked for');
        $this->assertStringContainsString('10.00', $message, 'the refusal does not say what is available');
    }

    /**
     * Every bad row at once, not one per submit.
     *
     * Reporting only the first refusal would still cost a round trip per bad
     * row, which is the complaint this whole change answers.
     */
    public function test_all_the_refusals_come_back_together(): void
    {
        $this->asHr()->from(route('balances.index'))
            ->post(route('balances.adjust', $this->employee), [
                'days' => [$this->vl->id => -99, $this->sl->id => -99],
                'remarks' => 'Both impossible',
            ]);

        $message = session('errors')->first('days');

        $this->assertStringContainsString('Vacation Leave', $message);
        $this->assertStringContainsString('Sick Leave', $message,
            'only the first bad row was reported, so the second costs another submit');

        $this->assertSame(25.0, $this->balance($this->vl));
        $this->assertSame(10.0, $this->balance($this->sl));
    }

    /**
     * The reason is required, and the employee is told it is theirs to read.
     *
     * It is stored on the ledger entry and rendered on the employee's own
     * dashboard, which is not obvious from a box labelled "Remarks" -- and it
     * changes what an officer writes in it.
     */
    public function test_the_reason_is_required_and_reaches_the_employee(): void
    {
        $this->asHr()->post(route('balances.adjust', $this->employee), [
            'days' => [$this->vl->id => 1],
        ])->assertSessionHasErrors('remarks');

        $this->asHr()->post(route('balances.adjust', $this->employee), [
            'days' => [$this->vl->id => 1],
            'remarks' => 'Carry-over approved by the Mayor',
        ])->assertRedirect();

        $this->assertDatabaseHas('leave_history', [
            'user_id' => $this->employee->id,
            'remarks' => 'Carry-over approved by the Mayor',
        ]);

        $html = $this->get(route('balances.index'))->assertOk()->getContent();
        $this->assertStringContainsString('The employee sees this on their credit history', $html,
            'the form no longer tells the officer who reads the reason');
    }

    /** A leave type the form does not offer is not adjustable through it. */
    public function test_a_type_outside_the_offered_set_is_refused(): void
    {
        $other = LeaveType::where('deductible', false)
            ->whereNotIn('code', ['VL', 'SL'])->first();

        if ($other === null) {
            $this->markTestSkipped('every leave type is adjustable in this installation');
        }

        $this->asHr()->post(route('balances.adjust', $this->employee), [
            'days' => [$other->id => 5],
            'remarks' => 'Not through this page',
        ])->assertStatus(422);
    }

    /** Only an account that may manage balances can move them. */
    public function test_an_employee_cannot_adjust_their_own_credits(): void
    {
        $this->actingAs($this->employee);
        session(['otp_verified' => true]);

        $this->post(route('balances.adjust', $this->employee), [
            'days' => [$this->vl->id => 500],
            'remarks' => 'A gift to myself',
        ])->assertForbidden();

        $this->assertSame(25.0, $this->balance($this->vl));
    }
}
