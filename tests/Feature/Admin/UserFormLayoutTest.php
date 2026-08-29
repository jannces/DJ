<?php

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The New User form's layout.
 *
 * It is the longest form in the system — twenty-one fields across four cards —
 * so the things that go wrong are alignment and reading order rather than
 * behaviour. What is checked here is the shape of the markup, because that is
 * what the raggedness came from.
 */
class UserFormLayoutTest extends TestCase
{
    use RefreshDatabase;

    private function form(): string
    {
        $this->seedCore();
        $this->actingAs($this->makeUser('system-admin'));
        session(['otp_verified' => true]);

        return $this->get('/users/create')->assertOk()->getContent();
    }

    /**
     * Every field in a card sits on the same vertical gridlines.
     *
     * Employment mixed a two-column row with a three-column one, so
     * Department's right edge fell at half the card while Employment status's
     * fell at a third — two sets of gridlines inside one box, which is what
     * reads as ragged.
     */
    public function test_a_card_does_not_mix_two_grids(): void
    {
        $html = $this->form();

        preg_match_all('#<div class="card">(.*?)</div>\s*</div>\s*</div>#s', $html, $cards);

        $offenders = [];
        foreach ($cards[1] as $card) {
            preg_match('#<div class="card-header[^>]*>\s*([^<]*)#', $card, $name);
            preg_match_all('#<div class="row g-3">(.*)#s', $card, $body);

            if (empty($body[1])) {
                continue;
            }
            preg_match_all('#col-md-(\d+)#', $body[1][0], $widths);
            $used = array_unique($widths[1]);

            // 4 and 8 are the same gridlines; 6 and 4 are not.
            $thirds = array_diff($used, ['4', '8', '12']);
            $halves = array_diff($used, ['6', '12']);

            if ($thirds !== [] && $halves !== []) {
                $offenders[] = trim($name[1] ?? '?').': '.implode('/', $used);
            }
        }

        $this->assertSame([], $offenders,
            'these cards put their fields on two different sets of gridlines');
    }

    /**
     * The card header is a space-between row, so a bare span beside the title
     * is a second item and gets flung to the far right. "Roles *" had its
     * asterisk sitting alone at the opposite edge of the card.
     */
    public function test_the_required_marker_stays_with_the_word_it_marks(): void
    {
        $html = $this->form();

        preg_match('#<div class="card-header fw-semibold">\s*<span>Roles\s*<span class="req">\*</span></span>#', $html, $m);

        $this->assertNotEmpty($m,
            'the asterisk is a separate item in the header row, so it floats to the far edge');
    }

    /** A required section with no instruction reads as an optional one. */
    public function test_the_roles_card_says_what_is_expected(): void
    {
        $this->assertStringContainsString('choose at least one', $this->form());
    }

    /**
     * The two columns end on the same line.
     *
     * The three stacked cards are taller than five roles, so the roles card
     * has to stretch to match rather than stopping short and leaving the row
     * with a ragged edge. The slack falls between the role list and the
     * footer, not under the last role.
     *
     * It deliberately no longer sticks: the commit moved below both columns,
     * so nothing in that column has to stay in view, and a card that stuck
     * could not also end level with the one beside it.
     */
    public function test_the_two_columns_end_level(): void
    {
        $css = preg_replace('/\s+/', '', file_get_contents(public_path('css/app.css')));

        $this->assertMatchesRegularExpression('/\.user-form-grid\{[^}]*align-items:start/', $css);
        $this->assertMatchesRegularExpression('/\.user-form-side>\.card\{[^}]*flex:1 1 auto/',
            preg_replace('/flex:1(\s*)1(\s*)auto/', 'flex:1 1 auto', $css),
            'the roles card does not stretch, so the columns end at different points');
        $this->assertMatchesRegularExpression('/\.role-foot\{[^}]*margin-top:auto/', $css,
            'the slack dangles under the last role instead of falling above the footer');

        $this->assertDoesNotMatchRegularExpression('/\.user-form-side\{position:sticky/', $css,
            'a sticky column cannot also end level with the one beside it');
    }

    /** The commit sits below both columns, on the right. */
    public function test_the_actions_are_below_both_columns_and_right(): void
    {
        $html = $this->form();
        $css = preg_replace('/\s+/', '', file_get_contents(public_path('css/app.css')));

        $this->assertMatchesRegularExpression('/\.form-actions\{[^}]*justify-content:flex-end/', $css);
        $this->assertLessThan(
            strpos($html, 'class="form-actions"'),
            strpos($html, 'user-form-side'),
            'the buttons come before the columns they commit');
    }

    /** The order the page reads in is the order the work is done in. */
    public function test_the_sections_are_in_the_order_they_are_filled(): void
    {
        $html = $this->form();
        // From the form itself: "Roles" is also a sidebar nav item, and the
        // navigation is not part of the page's reading order here.
        $html = substr($html, strpos($html, '<div class="user-form">'));

        $order = ['Create user', 'Account', 'Personal details', 'Employment', 'Roles'];
        $at = -1;

        foreach ($order as $heading) {
            $found = strpos($html, $heading);
            $this->assertNotFalse($found, $heading.' is missing from the page');
            $this->assertGreaterThan($at, $found, $heading.' is out of order');
            $at = $found;
        }
    }

    /** The form still saves — layout work must not disturb the fields. */
    public function test_every_field_is_still_on_the_page(): void
    {
        $html = $this->form();

        foreach ([
            'name', 'email', 'username', 'employee_no',
            'first_name', 'middle_name', 'last_name', 'gender', 'civil_status',
            'birth_date', 'contact_no', 'address',
            'department_id', 'position_id', 'employment_status', 'salary', 'date_hired',
        ] as $field) {
            $this->assertMatchesRegularExpression('#name="'.$field.'"#', $html,
                $field.' was lost in the layout change');
        }
    }
}
