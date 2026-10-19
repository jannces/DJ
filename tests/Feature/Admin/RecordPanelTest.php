<?php

namespace Tests\Feature\Admin;

use App\Models\Department;
use App\Models\Holiday;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The three short forms that moved into a floating panel.
 *
 * Each used to be pinned to the left third of its page, on screen whether or
 * not anybody was adding anything. What matters about the move is not that the
 * form floats — it is that a rejected submission still reaches the person who
 * made it. A panel that only opened on a click would be shut when the redirect
 * landed, so the errors would be invisible and the typed values gone.
 */
class RecordPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->actingAs($this->makeUser('hr'));
        session(['otp_verified' => true]);
    }

    /** @return array<int,array{0:string,1:string,2:string}> page, panel id, button label */
    public static function pages(): array
    {
        return [
            'positions' => ['/positions', 'position-new', 'New position'],
            'departments' => ['/departments', 'department-new', 'New department'],
            'holidays' => ['/holidays', 'holiday-new', 'Add holiday'],
        ];
    }

    // ------------------------------------------------------------- the list

    /**
     * @dataProvider pages
     */
    public function test_the_form_is_behind_a_button_and_shut_on_arrival(
        string $url, string $panel, string $label
    ): void {
        $html = $this->get($url)->assertOk()->getContent();

        $this->assertStringContainsString($label, $html, 'there is no button to open the panel');
        $this->assertStringContainsString('id="'.$panel.'"', $html);

        // Shut: the attribute the script reads is absent.
        preg_match('#<div class="modal fade" id="'.$panel.'"[^>]*>#', $html, $m);
        $this->assertNotEmpty($m, 'the panel is missing');
        $this->assertStringNotContainsString('data-open-on-load', $m[0],
            'the panel is open before anybody asked for it');
    }

    /**
     * @dataProvider pages
     *
     * Same place on every list: above the container, on the right. It was on
     * the title row on some pages and inside the container on others, which
     * read as unplanned rather than as a choice.
     */
    public function test_the_add_button_sits_above_the_container(
        string $url, string $panel, string $label
    ): void {
        $html = $this->get($url)->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '#<div class="list-actions">\s*<a [^>]*>\s*<i class="bi bi-plus-lg"></i>'.preg_quote($label, '#').'#',
            $html,
            $url.'\'s add button is not above the container'
        );
        // Above it, not inside it.
        $this->assertLessThan(
            strpos($html, '<div class="card">'),
            strpos($html, '<div class="list-actions">'),
            $url.'\'s add button is inside the container'
        );
    }

    // ------------------------------------------------- the part that matters

    public function test_a_rejected_position_reopens_the_panel_with_what_was_typed(): void
    {
        $html = $this->from('/positions')
            ->post('/positions', ['title' => '', 'salary_grade' => 'SG 99'])
            ->assertRedirect('/positions');

        $html = $this->followingRedirects()
            ->post('/positions', ['title' => '', 'salary_grade' => 'SG 99'])
            ->assertOk()->getContent();

        preg_match('#<div class="modal fade" id="position-new"[^>]*>#', $html, $m);
        $this->assertStringContainsString('data-open-on-load', $m[0],
            'the errors are behind a shut panel');

        $this->assertStringContainsString('SG 99', $html, 'the typed value was thrown away');
        $this->assertStringContainsString('is-invalid', $html, 'the failing field is not marked');
    }

    public function test_a_rejected_department_reopens_the_panel(): void
    {
        Department::create(['name' => 'Treasury', 'code' => 'MTO']);

        // from() matters: store() sends the applicant back where they came
        // from, and without a referer that is the home page, not this list.
        $html = $this->from('/departments')
            ->followingRedirects()
            ->post('/departments', ['name' => 'Another', 'code' => 'MTO'])
            ->assertOk()->getContent();

        preg_match('#<div class="modal fade" id="department-new"[^>]*>#', $html, $m);
        $this->assertStringContainsString('data-open-on-load', $m[0]);
        $this->assertStringContainsString('Another', $html);
    }

    public function test_a_rejected_holiday_reopens_the_panel(): void
    {
        $html = $this->from('/holidays')
            ->followingRedirects()
            ->post('/holidays', ['date' => '', 'name' => 'Founding Day', 'scope' => 'local'])
            ->assertOk()->getContent();

        preg_match('#<div class="modal fade" id="holiday-new"[^>]*>#', $html, $m);
        $this->assertStringContainsString('data-open-on-load', $m[0]);
        $this->assertStringContainsString('Founding Day', $html);
    }

    // --------------------------------------------------------------- editing

    public function test_edit_opens_that_record_and_only_that_record(): void
    {
        $a = Position::create(['title' => 'Administrative Aide I', 'salary_grade' => 'SG 1']);
        $b = Position::create(['title' => 'Municipal Treasurer', 'salary_grade' => 'SG 24']);

        $html = $this->get('/positions/'.$a->id.'/edit')->assertOk()->getContent();

        preg_match('#<div class="modal fade" id="position-'.$a->id.'"[^>]*>#', $html, $open);
        preg_match('#<div class="modal fade" id="position-'.$b->id.'"[^>]*>#', $html, $shut);
        preg_match('#<div class="modal fade" id="position-new"[^>]*>#', $html, $new);

        $this->assertStringContainsString('data-open-on-load', $open[0]);
        $this->assertStringNotContainsString('data-open-on-load', $shut[0], 'the wrong panel opened');
        $this->assertStringNotContainsString('data-open-on-load', $new[0], 'the New panel opened too');

        $this->assertStringContainsString('SG 1', $html);
    }

    /**
     * A rejected edit comes back to the edit URL, so the same panel reopens —
     * this is why Edit stayed a real route rather than becoming a click.
     */
    public function test_a_rejected_edit_reopens_that_records_panel(): void
    {
        $position = Position::create(['title' => 'Engineer II', 'salary_grade' => 'SG 16']);

        $html = $this->from('/positions/'.$position->id.'/edit')
            ->followingRedirects()
            ->put('/positions/'.$position->id, ['title' => '', 'salary_grade' => 'SG 17'])
            ->assertOk()->getContent();

        preg_match('#<div class="modal fade" id="position-'.$position->id.'"[^>]*>#', $html, $m);
        $this->assertStringContainsString('data-open-on-load', $m[0]);
        $this->assertStringContainsString('SG 17', $html, 'the typed value was thrown away');
    }

    // ------------------------------------------------------- still reachable

    /**
     * The button is a real link to a real URL, so the page works whether or not
     * the script runs: with it, Bootstrap opens the panel and cancels the
     * navigation; without it, the link loads a page whose panel is already open.
     */
    public function test_the_new_button_is_a_link_that_works_without_the_script(): void
    {
        foreach ([
            ['/positions', '/positions/create', 'position-new'],
            ['/departments', '/departments/create', 'department-new'],
        ] as [$list, $create, $panel]) {
            $html = $this->get($list)->assertOk()->getContent();
            $this->assertMatchesRegularExpression(
                '#<a href="[^"]*'.preg_quote($create, '#').'"[^>]*data-bs-toggle="modal"#',
                $html,
                $list.'\'s button is not a link, so it does nothing without the script'
            );

            preg_match('#<div class="modal fade" id="'.$panel.'"[^>]*>#',
                $this->get($create)->assertOk()->getContent(), $m);
            $this->assertStringContainsString('data-open-on-load', $m[0],
                $create.' should render with the panel open');
        }
    }

    public function test_the_panel_lays_itself_out_as_a_card_with_no_script(): void
    {
        $html = $this->get('/positions')->assertOk()->getContent();

        $this->assertStringContainsString('<noscript>', $html,
            'nothing lays the panel out when the script does not run');
        $this->assertMatchesRegularExpression(
            '/\.modal\[data-open-on-load\]\{[^}]*display:block/',
            preg_replace('/\s+/', '', $html)
        );
    }

    // ------------------------------------------------------------ saving works

    public function test_each_panel_still_saves(): void
    {
        $this->post('/positions', ['title' => 'Engineer II', 'salary_grade' => 'SG 16'])
            ->assertRedirect();
        $this->assertDatabaseHas('positions', ['title' => 'Engineer II']);

        $this->post('/departments', ['name' => 'Municipal Health Office', 'code' => 'MHO'])
            ->assertRedirect();
        $this->assertDatabaseHas('departments', ['code' => 'MHO']);

        $this->post('/holidays', ['date' => '2026-07-04', 'name' => 'Alicia Town Fiesta', 'scope' => 'local'])
            ->assertRedirect();
        $this->assertDatabaseHas('holidays', ['name' => 'Alicia Town Fiesta']);
    }

    /**
     * The panel says re-saving a listed date replaces it, and that is the only
     * way to correct a holiday — so it has to actually work. It did not: the
     * column holds midnight, the lookup passed a bare 'Y-m-d', nothing matched
     * and the insert hit the unique index with a 500.
     */
    public function test_saving_a_date_that_is_already_listed_replaces_it(): void
    {
        $this->post('/holidays', ['date' => '2026-06-12', 'name' => 'Araw ng Kalayaan', 'scope' => 'national'])
            ->assertRedirect();

        $this->assertSame(1, Holiday::whereDate('date', '2026-06-12')->count());
        $this->assertDatabaseHas('holidays', ['name' => 'Araw ng Kalayaan']);
        $this->assertDatabaseMissing('holidays', ['name' => 'Independence Day', 'date' => '2026-06-12 00:00:00']);
    }

    /** The form that stayed a page stayed a page. */
    public function test_the_long_forms_were_not_moved(): void
    {
        $this->actingAs($this->makeUser('system-admin'));
        session(['otp_verified' => true]);

        $html = $this->get('/users/create')->assertOk()->getContent();

        $this->assertStringNotContainsString('data-open-on-load', $html,
            'the 21-field user form became a panel');
        $this->assertStringContainsString('Create user', $html);
    }

    /** Holidays are keyed by date, so there is no edit panel to open. */
    public function test_holidays_offer_no_edit_panel(): void
    {
        Holiday::create(['date' => '2026-07-04', 'name' => 'Alicia Town Fiesta', 'scope' => 'local']);

        $html = $this->get('/holidays')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'class="modal fade"'),
            'a holiday is replaced by re-adding its date; there is nothing else to open');
    }
}
