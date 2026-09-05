<?php

namespace Tests\Feature\Leave;

use App\Models\Department;
use App\Models\EmployeeProfile;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What an employee sees when the server rejects the application.
 *
 * The form has four steps and the browser shows one at a time, which is a good
 * way to FILL it in and was a very bad way to be told it is wrong: the banner
 * read "check the highlighted fields below" over step 1, which had no
 * highlighted field, while the real errors sat on steps 2 and 3 behind
 * display:none. Nine of the ten validated fields rendered no message at all --
 * only leave_type_id had an @error block -- so there was nothing to find even
 * after clicking through.
 *
 * Three things fix it and this pins all three: every field says what is wrong
 * with it, a summary at the top links to each one, and the form unfolds so the
 * links have somewhere to land.
 */
class RejectedFormTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();

        $office = Department::create(['name' => 'Municipal Treasurers Office', 'code' => 'MTO']);
        $this->employee = $this->makeUser('employee');
        EmployeeProfile::factory()->create([
            'user_id' => $this->employee->id,
            'employee_no' => 'EMP-0001',
            'department_id' => $office->id,
            'position_id' => Position::factory()->create()->id,
        ]);

        $this->actingAs($this->employee);
        session(['otp_verified' => true]);
    }

    /** Submit nothing, then look at the form we are sent back to. */
    private function rejected(array $payload = []): string
    {
        $this->post('/leave', $payload);

        return $this->followingRedirects()->get('/leave/apply')->assertOk()->getContent();
    }

    /**
     * The summary names every problem and links to the control that caused it.
     *
     * The links matter more than the list. "Your application was not submitted"
     * on its own is what the page said before, and it left the employee to hunt.
     */
    public function test_every_problem_is_named_and_linked(): void
    {
        $html = $this->rejected();

        $this->assertMatchesRegularExpression(
            '/<div class="alert alert-danger no-print lf-summary" role="alert" tabindex="-1"/', $html,
            'the summary is not an alert the browser can announce or focus');

        foreach (['#lf-type', '#date_filed', '#start_date', '#end_date', '#applicant_signature'] as $anchor) {
            $this->assertStringContainsString('<li><a href="'.$anchor.'">', $html,
                "the summary does not link to $anchor");
        }
    }

    /**
     * The four steps unfold, because a link cannot scroll to a hidden target.
     *
     * This is the assertion that fails if somebody restores the stepped view on
     * the error path: the anchors would still be in the markup and would still
     * do nothing.
     */
    public function test_the_form_unfolds_so_the_links_have_somewhere_to_land(): void
    {
        $this->assertStringContainsString('class="lf-steps lf-flat"', $this->rejected());

        // ...and it does NOT unfold on a first visit, which is the whole point
        // of the stepped form.
        $this->assertStringContainsString('class="lf-steps"',
            $this->get('/leave/apply')->assertOk()->getContent());
    }

    /** The step indicators survive. Losing them would make it a different form. */
    public function test_the_rail_stays_and_points_at_the_failing_steps(): void
    {
        $html = $this->rejected();

        $this->assertStringContainsString('<ol class="lf-track no-print">', $html);
        $this->assertStringContainsString('class="has-error"', $html,
            'no step is marked as the one carrying a problem');
        $this->assertStringContainsString('<span class="visually-hidden">has a problem</span>', $html,
            'the failing step is marked by colour alone');
    }

    /**
     * The field itself carries the message, not just the summary.
     *
     * A summary alone sends you to the right control and then abandons you
     * there; by the time you have scrolled to it the reason is off-screen.
     */
    public function test_the_message_sits_with_the_field_that_caused_it(): void
    {
        $html = $this->rejected();

        // Four of the five failures are text inputs wired the same way. The
        // fifth is 6.A, which had the only @error block on the page before this
        // and keeps its own markup, so it is asserted separately below.
        $this->assertSame(4, substr_count($html, 'invalid-feedback d-block'),
            'the failing fields do not each carry their own message');
        $this->assertStringContainsString(
            'Choose the type of leave you are applying for in section 6.A.', $html,
            '6.A lost the message it already had');
        $this->assertStringContainsString('id="start_date" type="date" name="start_date"', $html);
        $this->assertMatchesRegularExpression(
            '/id="start_date"[^>]*class="form-control\s+is-invalid\s*"/', $html,
            'the field the summary points at is not marked as the bad one');
    }

    /**
     * The messages use the words printed on the CSC sheet.
     *
     * "The start date field is required" names a database column at somebody
     * looking at a box labelled "From".
     */
    public function test_the_wording_matches_the_form_not_the_schema(): void
    {
        $html = $this->rejected();

        $this->assertStringContainsString('The first day of leave field is required.', $html);
        $this->assertStringContainsString('The signature of applicant field is required.', $html);
        $this->assertStringNotContainsString('The start date field is required.', $html);
    }

    /** A date range that runs backwards is explained, not just refused. */
    public function test_a_backwards_range_says_so_in_plain_words(): void
    {
        $html = $this->rejected([
            'start_date' => now()->addDays(9)->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
        ]);

        $this->assertStringContainsString(
            'The last day of leave cannot fall before the first day.', $html);
    }

    /**
     * Nothing on the page is announced by colour alone.
     *
     * The red outline is the fast signal for people who can see it; the text
     * under the field and the hidden label on the rail are what everybody else
     * gets. Removing either would leave a whole group with a form that refuses
     * and does not say why.
     */
    public function test_no_failure_is_signalled_by_colour_alone(): void
    {
        $html = $this->rejected();

        foreach (['is-invalid', 'invalid-feedback', 'has-error'] as $visual) {
            $this->assertStringContainsString($visual, $html);
        }
        $this->assertStringContainsString('role="alert"', $html);
        $this->assertStringContainsString('has a problem', $html);
    }
}
