<?php

namespace Tests\Feature\Dashboard;

use App\Models\Department;
use App\Models\EmployeeProfile;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The leave dashboards: an employee's own, and the management pane HR, the
 * Mayor and the Vice Mayor see beside it.
 *
 * The gate is the correction this file mostly records. The analytics used to
 * hang off `users.manage` / `security.dashboard` — held only by the System
 * Administrator, who holds no leave permission at all. So the one role with no
 * business reading leave figures was the only role that could, and the three
 * roles with authority over leave saw nothing. The gate is now
 * `leave.requests.view-all`, which is exactly the set of people who already
 * have the underlying data on the All Leave Requests page. No new permission.
 */
class LeaveDashboardTest extends TestCase
{
    use RefreshDatabase;

    private LeaveType $vl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->vl = LeaveType::where('code', 'VL')->firstOrFail();
    }

    private function visit(string $role): \Illuminate\Testing\TestResponse
    {
        $this->actingAs($this->makeUser($role));
        session(['otp_verified' => true]);

        return $this->get('/dashboard');
    }

    /** The role that actually holds leave.requests.view-all. */
    private function manager(): \Illuminate\Testing\TestResponse
    {
        return $this->visit('hr');
    }

    private function file(User $user, string $status, string $start, string $end, ?string $filed = null): LeaveRequest
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

    public function test_the_roles_with_authority_over_leave_get_the_analytics(): void
    {
        foreach (['hr', 'mayor'] as $role) {
            $this->visit($role)
                ->assertOk()
                ->assertSee('Waiting on a decision')
                ->assertSee('Applications filed per month')
                ->assertSee('Most applied leave type')
                ->assertSee('Applications by office', false);
        }
    }

    /**
     * The administrator loses a screen, not information. They were never
     * entitled to the leave figures — everything they administer is on the
     * security screen, and /dashboard now takes them there.
     *
     * The sidebar is untouched: `Dashboard` and `Security Dashboard` both stay
     * where they are and both arrive at the same page.
     */
    public function test_the_administrator_is_sent_to_their_own_dashboard(): void
    {
        $this->actingAs($this->makeUser('system-admin'));
        session(['otp_verified' => true]);

        $this->get('/dashboard')->assertRedirect(route('security.dashboard'));

        $this->followingRedirects()->get('/dashboard')
            ->assertOk()
            ->assertSee('Intrusions this week')
            ->assertDontSee('Most applied leave type')
            ->assertDontSee('Applications by office', false);
    }

    public function test_an_employee_sees_none_of_it(): void
    {
        $this->visit('employee')
            ->assertOk()
            ->assertDontSee('Applications by office', false)
            ->assertDontSee('Waiting longest')
            ->assertDontSee('Coverage risk')
            // ...but keeps their own page.
            ->assertSee('Credits remaining, by type', false);
    }

    /**
     * HR files leave like anybody else, so they get both panes behind one
     * Dashboard link — two tabs, not a thirteenth item in a rail that already
     * scrolls. config/menu.php is not edited.
     */
    public function test_hr_gets_both_panes_under_one_menu_item(): void
    {
        $html = $this->manager()->assertOk()->getContent();

        $this->assertStringContainsString('id="pane-mine"', $html);
        $this->assertStringContainsString('id="pane-mgt"', $html);
        $this->assertStringContainsString('Credits remaining, by type', $html, 'HR lost their own leave');
        $this->assertStringContainsString('Applications filed per month', $html);

        // The rail did not grow an entry for the second pane. Counted against
        // an employee's rail rather than against a fixed number, so the
        // assertion still means something if the layout ever renders the menu
        // in two places.
        $this->assertSame(
            substr_count($this->visit('employee')->getContent(), '<span>Dashboard</span>'),
            substr_count($html, '<span>Dashboard</span>'),
            'the second pane added a menu item; it is a tab, not a destination'
        );
    }

    /**
     * The analytics are administration, not approval. An approver's dashboard
     * is unchanged by this work — they reach every one of these figures through
     * the Reports module, which is where a "give me the numbers for period X"
     * question belongs.
     */
    /**
     * Nobody without the permission reaches the management pane, whatever else
     * they hold. The department head approves leave but does not hold
     * leave.requests.view-all, and this is a read of everyone's records.
     */
    public function test_the_pane_follows_the_permission_and_not_the_job_title(): void
    {
        foreach (['employee', 'department-head'] as $role) {
            $user = $this->makeUser($role);

            $this->assertSame(
                $user->hasPermission('leave.requests.view-all'),
                str_contains($this->visit($role)->assertOk()->getContent(), 'Applications filed per month'),
                $role.' sees the management pane exactly when it holds the permission'
            );
        }
    }

    /**
     * Outcome is part-to-whole, so a segment rendered at the wrong width is a
     * lie the reader cannot check. The widths must add to 100.
     *
     * A bar rather than the pie this replaced: both answer "what proportion
     * ended up approved", but a bar survives a fourth status arriving without
     * turning into a colour wheel, and lengths are compared more accurately
     * than wedge angles.
     */
    public function test_the_outcome_segments_add_up_to_the_whole(): void
    {
        $employee = $this->makeUser('employee');
        foreach (['approved', 'approved', 'approved', 'rejected'] as $status) {
            $this->file($employee, $status, now()->toDateString(), now()->toDateString());
        }

        $html = $this->manager()->assertOk()->getContent();

        preg_match('#<div class="sb">(.*?)</div>#s', $html, $bar);
        $this->assertNotEmpty($bar, 'the outcome bar is missing');

        preg_match_all('/width:([\d.]+)%/', $bar[1], $widths);
        $this->assertSame([75.0, 25.0], array_map('floatval', $widths[1]),
            'three approved of four filed, one rejected');
        $this->assertCount(2, $widths[1], 'a bucket with nothing in it gets no segment');

        // The key still names all three, so a zero reads as "none" rather than
        // vanishing from the card.
        foreach (['Approved', 'Rejected', 'Waiting'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
    }

    public function test_the_outcome_chart_columns_add_up_to_the_year(): void
    {
        $employee = $this->makeUser('employee');
        foreach (['approved', 'approved', 'rejected', 'pending'] as $status) {
            $this->file($employee, $status, now()->toDateString(), now()->toDateString());
        }
        // Cancelled is a withdrawal, not an outcome anybody decided.
        $this->file($employee, 'cancelled', now()->toDateString(), now()->toDateString());

        $outcome = app(DashboardService::class)->applicationsByOutcome((int) now()->year);

        $this->assertSame(2, $outcome['totals']['approved']);
        $this->assertSame(1, $outcome['totals']['rejected']);
        $this->assertSame(1, $outcome['totals']['pending']);
        $this->assertSame(4, $outcome['totals']['total'], 'cancelled applications must not be counted');

        $this->assertCount(12, $outcome['months'], 'the year is always twelve columns, not just the busy ones');
        $this->assertSame(
            $outcome['totals']['total'],
            array_sum(array_column($outcome['months'], 'total')),
            'the columns must add up to the year, or the chart contradicts the counter above it'
        );
    }

    /**
     * The heart of it: one employee off for five days is one employee, not
     * five. A distinct count cannot be recovered from the daily counts, which
     * is why the service keeps the user IDs per day rather than just totals.
     */
    public function test_a_long_leave_counts_as_one_employee_across_the_window(): void
    {
        $employee = $this->makeUser('employee');
        $other = $this->makeUser('employee');

        $start = now()->startOfMonth();
        $this->file($employee, 'approved', $start->toDateString(), $start->copy()->addDays(4)->toDateString());
        $this->file($other, 'approved', $start->toDateString(), $start->toDateString());

        $service = app(DashboardService::class);
        $month = $service->onLeaveWindows()['month'];

        $this->assertSame(2, $month['distinct'], 'five days off is still one person');
        $this->assertSame(2, $month['peak'], 'both are out on the first day');

        $byDay = $service->onLeaveByDay(now()->startOfMonth(), now()->endOfMonth());
        $this->assertSame(6, array_sum(array_map('count', $byDay)),
            'the daily expansion still totals the employee-days');
    }

    public function test_leave_outside_the_window_is_not_counted_and_leave_across_it_is(): void
    {
        $employee = $this->makeUser('employee');
        $inside = now()->startOfMonth();

        // Spans the start of the month from the month before.
        $this->file($employee, 'approved',
            $inside->copy()->subDays(3)->toDateString(),
            $inside->copy()->addDay()->toDateString());
        // Entirely in the future, well past the window.
        $this->file($this->makeUser('employee'), 'approved',
            now()->addMonths(3)->toDateString(),
            now()->addMonths(3)->addDay()->toDateString());
        // Approved is the only status that puts somebody out of the office.
        $this->file($this->makeUser('employee'), 'pending',
            $inside->toDateString(), $inside->toDateString());

        $service = app(DashboardService::class);
        $this->assertSame(1, $service->onLeaveWindows()['month']['distinct']);

        $byDay = $service->onLeaveByDay(now()->startOfMonth(), now()->endOfMonth());
        $this->assertSame(2, array_sum(array_map('count', $byDay)),
            'only the two days that fall inside the month should be counted');
    }

    /**
     * The whole reason this panel is cheap enough not to need a cache.
     *
     * Leave is a date *range*, so all three windows come out of one overlap
     * query and are expanded into days in PHP. Asking the database for a count
     * per day would be thirty-one round trips to rebuild something a few dozen
     * rows already contain — and then a cache would be needed to hide it.
     */
    public function test_all_three_windows_come_from_a_single_query(): void
    {
        $employee = $this->makeUser('employee');
        foreach (range(0, 5) as $offset) {
            $day = now()->startOfMonth()->addDays($offset * 3);
            $this->file($employee, 'approved', $day->toDateString(), $day->copy()->addDay()->toDateString());
        }

        \DB::enableQueryLog();
        $windows = app(DashboardService::class)->onLeaveWindows();
        $queries = \DB::getQueryLog();
        \DB::disableQueryLog();

        $this->assertCount(1, $queries,
            'today, this week and this month should cost one query between them, not one per day');
        foreach (['today', 'week', 'month'] as $key) {
            $this->assertArrayHasKey($key, $windows);
        }
    }

    public function test_employees_with_no_department_are_reported_not_dropped(): void
    {
        $department = Department::create(['name' => 'Engineering', 'code' => 'ENG']);

        $placed = $this->makeUser('employee');
        EmployeeProfile::factory()->create(['user_id' => $placed->id, 'department_id' => $department->id]);
        $this->file($placed, 'approved', now()->toDateString(), now()->toDateString());

        $stray = $this->makeUser('employee');
        EmployeeProfile::factory()->create(['user_id' => $stray->id, 'department_id' => null]);
        $this->file($stray, 'approved', now()->toDateString(), now()->toDateString());

        $rows = app(DashboardService::class)
            ->applicationsByDepartment(now()->startOfYear(), now()->endOfYear());

        $names = array_column($rows, 'name');
        $this->assertContains('Engineering', $names);
        $this->assertContains('Unassigned', $names,
            'an employee with no department must show as a bar, not vanish from the chart');

        // Per head is the number worth acting on; a department of one that files
        // once is not the same as a department of ten that files once.
        $engineering = collect($rows)->firstWhere('name', 'Engineering');
        $this->assertSame(1, $engineering['staff']);
        $this->assertSame(1.0, $engineering['per_head']);
        $this->assertFalse($engineering['muted']);

        $stray = collect($rows)->firstWhere('name', 'Unassigned');
        $this->assertTrue($stray['muted'], 'the unassigned bar is drawn as a data gap, not a department');
    }

    public function test_the_ranked_leave_types_count_applications_not_days(): void
    {
        $employee = $this->makeUser('employee');
        $sl = LeaveType::where('code', 'SL')->firstOrFail();

        // One long vacation, two short sick leaves. By days VL wins; by
        // applications — which is what was asked for — SL does.
        $this->file($employee, 'approved', now()->startOfMonth()->toDateString(),
            now()->startOfMonth()->addDays(9)->toDateString());
        foreach ([0, 1] as $offset) {
            LeaveRequest::factory()->create([
                'user_id' => $employee->id,
                'leave_type_id' => $sl->id,
                'status' => 'approved',
                'date_filed' => now()->startOfMonth()->addDays($offset)->toDateString(),
                'start_date' => now()->startOfMonth()->addDays($offset)->toDateString(),
                'end_date' => now()->startOfMonth()->addDays($offset)->toDateString(),
            ]);
        }

        $rows = app(DashboardService::class)
            ->mostAppliedTypes(now()->startOfMonth(), now()->endOfMonth());

        $this->assertSame('SL', $rows[0]['label'], 'the axis label is the CSC code');
        $this->assertSame('Sick Leave', $rows[0]['name'], 'the full name is the hover readout');
        $this->assertSame(2, $rows[0]['value']);
    }

    /**
     * Every leave type gets a column, including the ones nobody used. A type
     * with no applications is a real answer to "what do people apply for"; a
     * chart that silently drops it cannot be told apart from one where the type
     * does not exist at all.
     */
    public function test_every_active_leave_type_gets_a_column(): void
    {
        $employee = $this->makeUser('employee');
        $this->file($employee, 'approved', now()->toDateString(), now()->toDateString());

        $rows = app(DashboardService::class)
            ->mostAppliedTypes(now()->startOfMonth(), now()->endOfMonth(), 'this month');

        $active = LeaveType::where('active', true)->count();
        $this->assertCount($active, $rows, 'every active leave type belongs on the chart');
        $this->assertSame(1, $rows[0]['value'], 'the busiest type sorts first');
        $this->assertSame(0, end($rows)['value'], 'the unused ones sort last, at zero');
    }

    /**
     * Colour does not follow the ranking, because sort order already carries
     * it. Exactly one row per chart is the hero; the rest are grey.
     *
     * This replaces a per-row colour slot. Painting each row repeated the
     * ranking in a second channel, implied a category difference that was not
     * there, and repainted the whole chart every time the ranking moved between
     * This month and This year.
     */
    public function test_only_the_leading_row_carries_a_colour(): void
    {
        $employee = $this->makeUser('employee');
        $this->file($employee, 'approved', now()->toDateString(), now()->toDateString());

        $html = $this->manager()->assertOk()->getContent();

        // One hero per chart, and never one on a chart whose leader is zero.
        preg_match_all('/<div class="hb-r"\s*\n?\s*data-hero/', $html, $heroes);
        $this->assertNotEmpty($heroes[0], 'no chart is naming its leader');

        $this->assertStringNotContainsString('tone-', $html,
            'the per-row colour slots are back; the ranking is now encoded twice');
    }

    /** The service no longer hands the view a colour slot to key off. */
    public function test_the_chart_rows_carry_no_colour_slot(): void
    {
        $rows = app(DashboardService::class)
            ->mostAppliedTypes(now()->startOfMonth(), now()->endOfMonth(), 'this month');

        $this->assertArrayNotHasKey('tone', $rows[0]);
    }

    public function test_a_leave_type_keeps_its_place_when_the_ranking_changes(): void
    {
        $employee = $this->makeUser('employee');
        $sl = LeaveType::where('code', 'SL')->firstOrFail();

        // VL leads the year, SL leads the month. Three VL at the start of the
        // year so it still leads once the month's two SL are counted into it.
        foreach ([0, 1, 2] as $offset) {
            $day = now()->startOfYear()->addDays($offset)->toDateString();
            $this->file($employee, 'approved', $day, $day);
        }
        foreach ([0, 1] as $offset) {
            LeaveRequest::factory()->create([
                'user_id' => $employee->id,
                'leave_type_id' => $sl->id,
                'status' => 'approved',
                'date_filed' => now()->startOfMonth()->addDays($offset)->toDateString(),
                'start_date' => now()->startOfMonth()->addDays($offset)->toDateString(),
                'end_date' => now()->startOfMonth()->addDays($offset)->toDateString(),
            ]);
        }

        $service = app(DashboardService::class);
        $month = collect($service->mostAppliedTypes(now()->startOfMonth(), now()->endOfMonth(), 'm'));
        $year = collect($service->mostAppliedTypes(now()->startOfYear(), now()->endOfYear(), 'y'));

        $this->assertSame('SL', $month->first()['label']);
        $this->assertSame('VL', $year->first()['label']);

        // The same type appears in both windows with its own count — the
        // ranking moves, the type does not disappear.
        $this->assertSame(2, $month->firstWhere('label', 'SL')['value']);
        $this->assertSame(2, $year->firstWhere('label', 'SL')['value']);
        $this->assertSame(3, $year->firstWhere('label', 'VL')['value']);
    }

    /**
     * The panels are drawn in HTML and inline SVG. No canvas means no Chart.js
     * on this page, and no way to reproduce the runaway-growth bug the Security
     * Dashboard had.
     */
    public function test_the_analytics_need_no_script_and_no_canvas(): void
    {
        $this->file($this->makeUser('employee'), 'approved',
            now()->toDateString(), now()->toDateString());

        $html = $this->manager()->assertOk()->getContent();

        $this->assertStringNotContainsString('<canvas', $html);

        // Three forms: filing over the year is a line, outcome is one split
        // bar, leave types and offices are sideways bars.
        $this->assertStringContainsString('class="ln"', $html);
        $this->assertStringContainsString('class="hb-f"', $html);
        $this->assertStringContainsString('<div class="sb">', $html);

        // The outcome chart carries its table, so it is not readable by colour
        // alone.
        $this->assertStringContainsString('Show the numbers', $html);
    }

    /**
     * A line with no numbers on it says only "up" and "down".
     *
     * And the ticks have to be whole: these are counts, so a near-empty month
     * must not label its axis 1 · 0.5 · 0.
     */
    public function test_the_line_chart_labels_its_own_axis(): void
    {
        $this->file($this->makeUser('employee'), 'approved',
            now()->toDateString(), now()->toDateString());

        $html = $this->manager()->assertOk()->getContent();

        preg_match('#<div class="ln-y">(.*?)</div>#s', $html, $axis);
        $this->assertNotEmpty($axis, 'the line chart has no vertical axis');

        preg_match_all('/<span>([\d.]+)<\/span>/', $axis[1], $ticks);
        $this->assertNotEmpty($ticks[1]);

        foreach ($ticks[1] as $tick) {
            $this->assertSame($tick, (string) (int) $tick,
                'a count has no half: the axis must round to whole numbers');
        }

        $this->assertSame('0', end($ticks[1]), 'the scale must be zero-based');
    }

    /**
     * Every colour class the charts reach for must actually be defined.
     *
     * This has now bitten twice: a class named in Blade with no rule behind it
     * fails silently — an SVG fill falls back to black, a bar falls back to the
     * default — and nothing in the test suite noticed either time. The charts
     * are the one place where a missing rule is invisible to a page that still
     * renders "correctly".
     */
    public function test_every_chart_colour_class_is_defined_in_the_stylesheet(): void
    {
        $employee = $this->makeUser('employee');
        foreach (['approved', 'rejected', 'pending'] as $status) {
            $this->file($employee, $status, now()->toDateString(), now()->toDateString());
        }

        $html = $this->manager()->assertOk()->getContent();
        $css = file_get_contents(public_path('css/app.css'));

        preg_match_all('/\b(?:sb-(?:approved|rejected|pending)|tag-(?:blocked|open))\b/', $html, $matches);
        $used = array_unique($matches[0]);
        $this->assertNotEmpty($used, 'the charts should be painting something');

        foreach ($used as $class) {
            $this->assertMatchesRegularExpression(
                '/\.'.preg_quote($class, '/').'\s*\{[^}]*(fill|background)\s*:/',
                $css,
                ".{$class} is used by a chart but the stylesheet gives it no colour"
            );
        }
    }

    /**
     * The app puts `data-bs-theme` on <html> from localStorage('lms-theme') and
     * falls back to the operating system only on a first visit. A chart keyed
     * to prefers-color-scheme would therefore stay light while the topbar
     * toggle turned the page around it dark — dark cards, light charts.
     */
    public function test_the_chart_colours_follow_the_apps_toggle_not_the_operating_system(): void
    {
        // Comments are stripped: the layer explains the trap in prose, and the
        // explanation must not be what satisfies the assertion.
        $css = preg_replace('#/\*.*?\*/#s', '', file_get_contents(public_path('css/app.css')));

        $this->assertStringNotContainsString('prefers-color-scheme', $css,
            'a chart keyed to the OS will disagree with the topbar toggle');

        foreach (['--ch-hero', '--ch-mute', '--ch-grid', '--k-good', '--k-warn', '--k-bad'] as $token) {
            $this->assertSame(2, substr_count($css, $token.':'),
                $token.' needs a light step and a dark one, and no more');
        }

        $this->assertMatchesRegularExpression('/\[data-bs-theme="dark"\][^{]*\{[^}]*--ch-hero:/s', $css,
            'the chart tokens have no dark step under the attribute the app actually sets');
    }

    /**
     * A leave request filed by somebody with no employee record must not fall
     * out of the department chart. An inner join dropped those rows entirely,
     * so the columns added up to less than the applications on record with
     * nothing on screen to say why.
     */
    public function test_an_employee_with_no_profile_still_reaches_the_department_chart(): void
    {
        $department = Department::create(['name' => 'Engineering', 'code' => 'ENG']);
        $placed = $this->makeUser('employee');
        EmployeeProfile::factory()->create(['user_id' => $placed->id, 'department_id' => $department->id]);
        $this->file($placed, 'approved', now()->toDateString(), now()->toDateString());

        // No employee_profiles row at all — not the same gap as a profile with
        // no department, but just as invisible before this.
        $this->file($this->makeUser('employee'), 'approved', now()->toDateString(), now()->toDateString());

        $rows = app(DashboardService::class)
            ->applicationsByDepartment(now()->startOfYear(), now()->endOfYear());

        $this->assertSame(2, array_sum(array_column($rows, 'value')),
            'every application on record belongs to some column');
        $this->assertSame('Unassigned', collect($rows)->firstWhere('muted', true)['name']);
    }

    /**
     * Retiring a leave type must not delete its history from the chart, or the
     * bars would add up to less than the pie above them.
     */
    public function test_a_retired_leave_type_keeps_its_history(): void
    {
        $employee = $this->makeUser('employee');
        $sl = LeaveType::where('code', 'SL')->firstOrFail();
        $this->file($employee, 'approved', now()->toDateString(), now()->toDateString());
        LeaveRequest::factory()->create([
            'user_id' => $employee->id, 'leave_type_id' => $sl->id, 'status' => 'approved',
            'date_filed' => now()->toDateString(),
            'start_date' => now()->toDateString(), 'end_date' => now()->toDateString(),
        ]);

        $sl->update(['active' => false]);

        $rows = app(DashboardService::class)
            ->mostAppliedTypes(now()->startOfMonth(), now()->endOfMonth(), 'this month');
        $retired = collect($rows)->firstWhere('label', 'SL');

        $this->assertNotNull($retired, 'a retired type with applications stays on the chart');
        $this->assertSame(1, $retired['value']);
        $this->assertTrue($retired['muted']);
        $this->assertStringContainsString('retired', $retired['name']);
        $this->assertSame(2, array_sum(array_column($rows, 'value')),
            'the columns must still add up to every application filed');
    }

    /**
     * Both switches — the period one and the two dashboard panes — are radio
     * inputs revealed with :has(). No script, and the second pane needs no
     * route of its own.
     */
    public function test_the_switches_are_plain_radio_inputs(): void
    {
        $html = $this->manager()->assertOk()->getContent();

        foreach (['types-month', 'types-year', 'pane-mine', 'pane-mgt'] as $id) {
            $this->assertStringContainsString('id="'.$id.'"', $html);
        }

        // The layout carries Bootstrap and the shared bundle, so the page has
        // scripts. What matters is that nothing scripted knows these ids exist.
        preg_match_all('#<script\b[^>]*>(.*?)</script>#s', $html, $scripts);
        foreach ($scripts[1] as $script) {
            foreach (['pane-mine', 'pane-mgt', 'types-month'] as $id) {
                $this->assertStringNotContainsString($id, $script,
                    'the panes must not need a script to switch');
            }
        }

        $css = file_get_contents(public_path('css/app.css'));
        $this->assertStringContainsString('#dash-tabs:has(#pane-mgt:checked)', $css);
        $this->assertStringContainsString('#an-types:has(#types-year:checked)', $css);
        // A browser without :has() (pre-2023) cannot do the reveal, so each
        // switch names the pane it falls back to. Asserted per switch rather
        // than by counting @supports blocks — the leave form has one of its own.
        $fallbacks = implode(' ', $this->supportsBlocks($css));
        foreach (['#an-types .pane-month', '#dash-tabs .pane-mine'] as $selector) {
            $this->assertStringContainsString($selector, $fallbacks,
                $selector.' has no fallback, so a browser without :has() shows no pane at all');
        }
    }

    /**
     * The bodies of every `@supports not selector(:has(*))` block.
     *
     * Brace-matched rather than matched with a regex: the blocks contain nested
     * rules, so `[^}]*` stops at the first inner close and silently finds
     * nothing.
     *
     * @return array<int,string>
     */
    private function supportsBlocks(string $css): array
    {
        $needle = '@supports not selector(:has(*))';
        $blocks = [];
        $at = 0;

        while (($found = strpos($css, $needle, $at)) !== false) {
            $open = strpos($css, '{', $found);
            $depth = 0;

            for ($i = $open; $i < strlen($css); $i++) {
                $depth += ($css[$i] === '{') ? 1 : (($css[$i] === '}') ? -1 : 0);
                if ($depth === 0) {
                    $blocks[] = preg_replace('/\s+/', ' ', substr($css, $open + 1, $i - $open - 1));
                    $at = $i;
                    break;
                }
            }

            $at = max($at, $found + 1);
        }

        $this->assertNotEmpty($blocks, 'nothing guards the :has() reveals at all');

        return $blocks;
    }
}
