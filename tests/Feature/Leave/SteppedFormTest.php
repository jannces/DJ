<?php

namespace Tests\Feature\Leave;

use App\Models\Department;
use App\Models\EmployeeProfile;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The application form is presented in four steps and is still one form.
 *
 * That sentence carries the whole risk. A stepped form that posts in pieces,
 * or that unmounts a panel, loses what the applicant has typed the moment they
 * press Back — so these tests are mostly about what has NOT changed: one
 * <form>, one submission, every field present in the DOM at all times, and a
 * sheet that still prints whole.
 *
 * The steps themselves are radio inputs revealed with CSS :has(). There is no
 * script, which is why the assertions below are on markup rather than on
 * behaviour: a browser test would prove the reveal works, and what actually
 * breaks a form like this is a field that stopped being posted.
 */
class SteppedFormTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;

    private LeaveType $vl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();

        $office = Department::create(['name' => 'Municipal Engineering Office', 'code' => 'MEO']);
        $this->vl = LeaveType::where('code', 'VL')->firstOrFail();

        $this->employee = $this->makeUser('employee');
        $this->employee->update(['name' => 'Juan Dela Cruz']);
        EmployeeProfile::factory()->create([
            'user_id' => $this->employee->id, 'employee_no' => 'EMP-0001',
            'first_name' => 'Juan', 'last_name' => 'Dela Cruz',
            'department_id' => $office->id, 'position_id' => Position::factory()->create()->id,
        ]);
        LeaveBalance::create([
            'user_id' => $this->employee->id, 'leave_type_id' => $this->vl->id,
            'earned' => 30, 'used' => 0, 'balance' => 30,
        ]);

        $this->actingAs($this->employee->fresh());
        session(['otp_verified' => true]);
    }

    private function form(): string
    {
        return $this->get('/leave/apply')->assertOk()->getContent();
    }

    // ------------------------------------------------------ still one form

    public function test_the_four_steps_live_inside_a_single_form(): void
    {
        $html = $this->form();

        $this->assertSame(1, substr_count($html, 'id="lf-form"'));
        $this->assertSame(4, substr_count($html, 'class="lf-step"'),
            'there are not four steps');

        // Every step is inside the one form that posts to leave.store.
        $form = substr($html, strpos($html, 'id="lf-form"'));
        $form = substr($form, 0, strrpos($form, '</form>'));
        foreach ([1, 2, 3, 4] as $n) {
            $this->assertStringContainsString('data-step="'.$n.'"', $form);
        }
    }

    /**
     * The fields still reach the server — all of them, in one post.
     *
     * This is the test that would fail if a step were ever made to submit on
     * its own, or if a panel were removed from the DOM instead of hidden.
     */
    public function test_one_submission_carries_fields_from_every_step(): void
    {
        $this->post('/leave', [
            'date_filed' => now()->toDateString(),          // step 1
            'leave_type_id' => [$this->vl->id],             // step 2
            'details' => ['location' => 'within_ph', 'location_specify' => 'Alicia'],
            'start_date' => now()->addDays(10)->toDateString(),   // step 3
            'end_date' => now()->addDays(12)->toDateString(),
            'applicant_signature' => 'Juan Dela Cruz',      // step 4
        ])->assertRedirect();

        $request = LeaveRequest::where('user_id', $this->employee->id)->firstOrFail();
        $this->assertSame($this->vl->id, $request->leave_type_id);
        $this->assertSame('Juan Dela Cruz', $request->applicant_signature);
        $this->assertSame('Alicia', $request->details['location_specify']);
    }

    // ----------------------------------------------- landing on the problem

    public function test_the_first_step_opens_when_there_is_nothing_wrong(): void
    {
        $this->assertStringContainsString('id="lf-s1" aria-label="Step 1 of 4: Employee" checked',
            $this->form());
    }

    /**
     * A rejected submission opens the step holding the fault.
     *
     * Without this the page returns on step 1 with its complaint pointing at a
     * field two Continues away — an error message about something you cannot
     * see is worse than no error message at all.
     */
    public function test_a_rejected_submission_opens_the_step_holding_the_error(): void
    {
        // Dates missing: that is step 3.
        $html = $this->from('/leave/apply')->post('/leave', [
            'date_filed' => now()->toDateString(),
            'leave_type_id' => [$this->vl->id],
            'applicant_signature' => 'Juan Dela Cruz',
        ])->assertRedirect('/leave/apply')
            ->getContent();

        $html = $this->get('/leave/apply')->assertOk()->getContent();

        $this->assertStringContainsString('id="lf-s3"', $html);
        $this->assertMatchesRegularExpression('/id="lf-s3"[^>]*checked/', $html,
            'the dates step is not opened for an error about the dates');
        $this->assertDoesNotMatchRegularExpression('/id="lf-s1"[^>]*checked/', $html);
    }

    /** The FIRST faulty step, not the last: errors are fixed from the top. */
    public function test_two_faults_open_the_earlier_step(): void
    {
        $this->from('/leave/apply')->post('/leave', [
            'date_filed' => now()->toDateString(),
            // no leave type (step 2) and no signature (step 4)
            'start_date' => now()->addDays(10)->toDateString(),
            'end_date' => now()->addDays(12)->toDateString(),
        ])->assertRedirect('/leave/apply');

        $html = $this->get('/leave/apply')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/id="lf-s2"[^>]*checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="lf-s4"[^>]*checked/', $html);
    }

    // --------------------------------------------------------- the gate

    /**
     * The Continue gate cannot deadlock.
     *
     * `.lf-step:has(:invalid) .lf-next` holds Continue shut while anything
     * required in that step is empty. A `required` field inside a 6.B block
     * that is hidden for the chosen leave type would still match :invalid --
     * CSS does not care that it is not on screen -- and would lock the step
     * with nothing visible to fill in. So the 6.B blocks carry none.
     */
    public function test_no_conditional_block_carries_a_required_field(): void
    {
        $html = $this->form();

        $blocks = substr($html, strpos($html, 'class="lf-grp'));
        // Step 2 ends at the card that opens step 3.
        $blocks = substr($blocks, 0, strpos($blocks, 'data-step="3"'));

        // ` required>` -- the ATTRIBUTE. Matching the bare word would catch the
        // help text ("Only required when Abroad is selected") and pass for the
        // wrong reason, which is worse than failing.
        $this->assertStringNotContainsString(' required>', $blocks,
            'a hidden 6.B field is required and would lock the step it is on');
        $this->assertStringContainsString('lf-grp-vl', $blocks,
            'the conditional blocks are not in this slice, so it proves nothing');
    }

    /** Exactly five required fields, spread so each step has at least one. */
    public function test_the_required_fields_are_the_five_the_form_has_always_had(): void
    {
        $html = $this->form();

        $this->assertSame(5, substr_count($html, ' required>'));
        foreach (['date_filed', 'leave_type_id[]', 'start_date', 'end_date', 'applicant_signature'] as $field) {
            $this->assertMatchesRegularExpression(
                '/name="'.preg_quote($field, '/').'"[^>]*required|required[^>]*name="'.preg_quote($field, '/').'"/',
                $html, $field.' is no longer required'
            );
        }
    }

    /**
     * The browser is not the gate.
     *
     * With native validation on, a required field left empty on a step that is
     * not showing makes the whole form unsubmittable AND SILENT: the browser
     * refuses, tries to focus a hidden control, and logs to a console no
     * employee will open. The button just stops working. novalidate removes
     * that; the server was always the real validator.
     */
    public function test_the_form_defers_validation_to_the_server(): void
    {
        $this->assertStringContainsString('novalidate', $this->form());

        // And the server does reject what the browser now lets through.
        $this->from('/leave/apply')->post('/leave', ['date_filed' => now()->toDateString()])
            ->assertSessionHasErrors(['leave_type_id', 'start_date', 'end_date', 'applicant_signature']);
    }

    // ------------------------------------------------------ section 7 & print

    public function test_section_seven_is_folded_and_holds_no_input(): void
    {
        $html = $this->form();

        $this->assertStringContainsString('<details class="card lf-after">', $html);

        $fold = substr($html, strpos($html, 'lf-after'));
        $fold = substr($fold, 0, strpos($fold, '</details>'));

        $this->assertStringContainsString('Action on application', $fold);
        $this->assertStringNotContainsString('<input', $fold,
            'section 7 is read-only and must carry no field the applicant can fill');
        $this->assertStringNotContainsString('<select', $fold);
    }

    /** The sheet is a CSC form: steps are a screen convenience, printing is not. */
    public function test_the_stylesheet_prints_every_step(): void
    {
        $css = file_get_contents(public_path('css/app.css'));

        $this->assertMatchesRegularExpression(
            '/@media print\{[^}]*\.lf-step\{ display:block !important;/s', $css,
            'the steps do not all print'
        );
        $this->assertStringContainsString('@supports not selector(:has(*))', $css,
            'a browser without :has() would get one step and a dead Continue button');
    }

    // ------------------------------------------------------------ commutation

    /**
     * 6.D is no longer asked, and nothing about the record changed.
     *
     * The column, its false default and its validation rule all stay, so the
     * control can come back without a migration.
     */
    public function test_commutation_is_not_asked_but_still_recorded(): void
    {
        $this->assertStringNotContainsString('name="commutation"', $this->form());

        $this->post('/leave', [
            'date_filed' => now()->toDateString(),
            'leave_type_id' => [$this->vl->id],
            'details' => ['location' => 'within_ph', 'location_specify' => 'Alicia'],
            'start_date' => now()->addDays(10)->toDateString(),
            'end_date' => now()->addDays(12)->toDateString(),
            'applicant_signature' => 'Juan Dela Cruz',
        ])->assertRedirect();

        $request = LeaveRequest::where('user_id', $this->employee->id)->firstOrFail();
        $this->assertFalse((bool) $request->commutation);

        // And the printed sheet still carries box 6.D, ticked "Not Requested".
        $this->assertStringContainsString('Not requested',
            $this->get("/leave/{$request->id}/preview")->assertOk()->getContent());
    }
}
