<?php

namespace Tests\Feature\Admin;

use App\Models\Department;
use App\Models\EmployeeProfile;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two decisions about the administrator's screens, both easy to undo by
 * accident because both are "just a class".
 *
 *   · The Security Dashboard opts out of the filled KPI tiles.
 *   · User Accounts draws the row and the heading the Employees list draws.
 */
class AdminChromeTest extends TestCase
{
    use RefreshDatabase;

    private string $css;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->css = file_get_contents(public_path('css/app.css'));

        $this->actingAs($this->makeUser('system-admin'));
        session(['otp_verified' => true]);
    }

    /**
     * The Security Dashboard is not painted in filled tiles.
     *
     * Colour has to stay available to mean something on this page -- red is an
     * intrusion, amber a failed sign-in, green quiet -- and a tile that is
     * already solid green cannot then turn green to say so. The leave
     * dashboards keep the filled treatment; this one asks for the plain card.
     */
    public function test_the_security_dashboard_opts_out_of_the_filled_tiles(): void
    {
        $this->get('/security')->assertOk()->assertSee('dash dash-plain', false);
    }

    /**
     * ...and the opt-out does not depend on where it sits in the sheet.
     *
     * The filled rules are `.dash .kpi`, specificity (0,2,0). Written as
     * `.dash-plain .kpi` the override would tie and be settled by file order,
     * which this stylesheet has already been bitten by more than once --
     * a media query anchored above the selector it meant to override, and a
     * cell padding set four times where the most specific rule won. Doubling
     * the class wins outright instead.
     *
     * If someone "tidies" this to a single class the page still looks right
     * until an unrelated edit moves a block, so this asserts the shape rather
     * than the effect.
     */
    public function test_the_opt_out_wins_on_specificity_not_on_position(): void
    {
        $this->assertStringContainsString('.dash.dash-plain .kpi{', $this->css,
            'the security opt-out no longer doubles its class, so it now ties '
            .'with .dash .kpi and depends on which rule sits lower in the file');

        $this->assertMatchesRegularExpression(
            '/\.dash\.dash-plain \.kpi\{[^}]*background:var\(--dash-surface\)/s', $this->css,
            'the plain tile has lost its own background and will fall back to '
            .'the filled one');
    }

    /**
     * User Accounts draws the Employees row: one labelled button, one menu.
     *
     * It was a pencil, a key, a clock and a bare caret -- four controls, no
     * words. The same person component and the same table on both pages did
     * not make them read as one system while the actions did that.
     */
    public function test_the_users_row_is_a_labelled_button_and_a_menu(): void
    {
        $this->seedPeople();
        $html = $this->get('/users')->assertOk()->getContent();

        $this->assertStringContainsString('>Edit</a>', $html,
            'the edit action is icon-only again');

        foreach (['Access &amp; permissions', 'Account history'] as $item) {
            $this->assertStringContainsString($item, $html,
                "$item is no longer reachable by name from the users list");
        }

        // The icon-only trio is what this replaced; none of them should be
        // back as a bare button in the row.
        $this->assertStringNotContainsString('bi-clock-history"></i></a>', $html,
            'the icon-only history button is back in the row');
    }

    /**
     * Its heading is the Employees heading, not the shared list band.
     *
     * .list-head plus .list-actions puts the title on one row and the add
     * button on another, leaving an empty band above the card. Employees goes
     * straight from a bare heading into its card, and these two pages were
     * asked to read as one screen.
     *
     * The other five pages that use the shared pattern are deliberately NOT
     * changed, so this asserts the exception rather than the rule.
     */
    public function test_the_users_heading_matches_the_employees_heading(): void
    {
        $html = $this->get('/users')->assertOk()->getContent();

        $this->assertStringNotContainsString('class="list-head"', $html,
            'User Accounts is back on the shared heading band');
        $this->assertStringNotContainsString('class="list-actions"', $html,
            'the New user button is back in a row of its own');

        $this->assertStringContainsString('New user', $html,
            'New user has been lost along with the band it used to sit in');
    }

    /** The pages that were left on the shared pattern are still on it. */
    public function test_the_other_lists_keep_the_shared_heading(): void
    {
        foreach (['/departments', '/positions', '/holidays'] as $url) {
            $this->actingAs($this->makeUser('hr'));
            session(['otp_verified' => true]);

            $this->get($url)->assertOk()->assertSee('class="list-head"', false);
        }
    }

    private function seedPeople(): void
    {
        $dept = Department::create(['name' => 'Municipal Treasurers Office', 'code' => 'MTO']);
        $position = Position::factory()->create();

        $u = $this->makeUser('employee');
        $u->update(['name' => 'Employee One Santos']);
        EmployeeProfile::factory()->create([
            'user_id' => $u->id, 'employee_no' => 'EMP-0001',
            'department_id' => $dept->id, 'position_id' => $position->id,
        ]);
    }
}
