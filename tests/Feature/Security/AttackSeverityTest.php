<?php

namespace Tests\Feature\Security;

use App\Models\BlockedIp;
use App\Models\IntrusionLog;
use App\Services\Security\IntrusionDetectionService;
use App\Services\Security\SecurityDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Attack severity, graded by escalation rather than read off the column.
 *
 * The detector records all three attack types at `high` and nothing at all at
 * `low` or `critical`, so charting the stored column would draw one bar and two
 * empty ones. That column says how bad the kind of thing is, and for injection,
 * input manipulation and a lockout the answer is always the same.
 *
 * What differs between two injection attempts is the pattern behind them, so
 * that is what is graded — in the analytics, not in the detector. Nothing about
 * what gets written to intrusion_logs changes.
 */
class AttackSeverityTest extends TestCase
{
    use RefreshDatabase;

    private SecurityDashboardService $security;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->security = app(SecurityDashboardService::class);
    }

    private function attack(string $ip, array $attributes = []): IntrusionLog
    {
        return IntrusionLog::create($attributes + [
            'category' => 'sqli',
            'severity' => 'high',
            'route' => 'employees',
            'method' => 'GET',
            'payload_excerpt' => "' OR '1'='1",
            'matched_rule' => 'sqli_signature',
            'ip' => $ip,
        ]);
    }

    /** @return array<string,int> grade => events */
    private function grades(): array
    {
        $rows = $this->security->attackSeverity()['rows'];

        return array_combine(array_column($rows, 'key'), array_column($rows, 'value'));
    }

    // ------------------------------------------------------------ the grading

    public function test_one_attempt_from_an_address_is_medium(): void
    {
        $this->attack('203.0.113.9');

        $this->assertSame(['critical' => 0, 'high' => 0, 'medium' => 1], $this->grades());
    }

    public function test_repeated_attempts_from_one_address_are_high(): void
    {
        $this->attack('203.0.113.9');
        $this->attack('203.0.113.9');
        $this->attack('203.0.113.9');

        $this->assertSame(['critical' => 0, 'high' => 3, 'medium' => 0], $this->grades());
    }

    /** The system deciding to shut an address out is a different kind of event. */
    public function test_a_blocked_source_is_critical(): void
    {
        $this->attack('203.0.113.9');
        $this->attack('203.0.113.9');
        BlockedIp::create([
            'ip' => '203.0.113.9',
            'reason' => 'Automatic block: 5 intrusion events in 10 minutes',
            'source' => 'auto',
            'expires_at' => now()->addDay(),
            'active' => true,
        ]);

        $this->assertSame(['critical' => 2, 'high' => 0, 'medium' => 0], $this->grades());
    }

    /** A lockout is the system acting too, even with no IP block behind it. */
    public function test_a_lockout_is_critical(): void
    {
        $this->attack('192.168.1.40', [
            'category' => 'auth_fail',
            'matched_rule' => 'lockout_threshold',
            'route' => 'login',
        ]);

        $this->assertSame(['critical' => 1, 'high' => 0, 'medium' => 0], $this->grades());
    }

    public function test_each_address_is_graded_on_its_own(): void
    {
        $this->attack('203.0.113.9');                       // one, medium
        $this->attack('198.51.100.4');
        $this->attack('198.51.100.4');                      // two, high
        $this->attack('192.168.1.40', [
            'category' => 'auth_fail', 'matched_rule' => 'lockout_threshold', 'route' => 'login',
        ]);                                                 // locked, critical

        $this->assertSame(['critical' => 1, 'high' => 2, 'medium' => 1], $this->grades());
    }

    // ------------------------------------------------------- what it counts

    /**
     * CSRF, request rate and privilege denials come from the same detector but
     * are not attacks of these kinds. A week of stale browser tabs must not
     * read as a week under attack.
     */
    public function test_it_counts_only_the_three_attack_types(): void
    {
        $this->attack('203.0.113.9');
        foreach (['csrf', 'rate', 'privilege', 'device'] as $category) {
            $this->attack('198.51.100.4', ['category' => $category, 'matched_rule' => $category]);
        }

        $summary = $this->security->attackSeverity();

        $this->assertSame(1, $summary['total'], 'something other than an attack is being counted');
        $this->assertSame(1, $summary['sources']);
    }

    public function test_the_window_is_a_week_and_the_week_before_is_reported(): void
    {
        $this->attack('203.0.113.9');
        $this->attack('203.0.113.9')->forceFill(['created_at' => now()->subDays(9)])->save();
        $this->attack('203.0.113.9')->forceFill(['created_at' => now()->subDays(30)])->save();

        $summary = $this->security->attackSeverity();

        $this->assertSame(1, $summary['total'], 'the window is not seven days');
        $this->assertSame(1, $summary['previous'], 'the week before is wrong');
    }

    // --------------------------------------------------- the number that matters

    /**
     * "Reached the app" is computed from which categories the detector refuses,
     * not asserted. It should read zero, permanently — and if it ever does not,
     * a rule is recording without preventing.
     */
    public function test_nothing_reaches_the_application(): void
    {
        $this->attack('203.0.113.9');
        $this->attack('198.51.100.4', ['category' => 'traversal', 'matched_rule' => 'traversal_signature']);
        $this->attack('192.168.1.40', ['category' => 'auth_fail', 'matched_rule' => 'lockout_threshold']);

        $this->assertSame(0, $this->security->attackSeverity()['reached']);
    }

    /** Every blocking rule is on the prevented list, so the zero is truthful. */
    public function test_the_prevented_list_matches_the_rules_that_block(): void
    {
        $blocking = app(IntrusionDetectionService::class)->blockingCategories();

        $missing = array_diff($blocking, IntrusionDetectionService::PREVENTED);

        $this->assertSame([], $missing,
            'a blocking rule is missing from PREVENTED, so "reached the app" over-reports');
    }

    // ------------------------------------------------------------- the panel

    public function test_the_scale_keeps_its_order_however_the_counts_fall(): void
    {
        $this->attack('203.0.113.9');

        $keys = array_column($this->security->attackSeverity()['rows'], 'key');

        $this->assertSame(['critical', 'high', 'medium'], $keys,
            'the scale sorted itself by count, which makes it not a scale');
    }

    /**
     * Severity leads the page.
     *
     * It used to sit to the side of "Attempts by type" and "Most targeted
     * pages", stacked two-against-one, which read as though it were their
     * sibling. It is not: it is the judgement about the set those two
     * describe, so it now comes first, in the row directly under the counters,
     * and the other two follow it.
     *
     * "Reached the app" as a label is gone with the redesign -- the figure is
     * stated as prose in the card's subtitle instead -- so what is asserted
     * here is the FACT, which has to survive any wording.
     */
    public function test_the_panel_leads_the_page(): void
    {
        $this->attack('203.0.113.9');
        $this->actingAs($this->makeUser('system-admin'));
        session(['otp_verified' => true]);

        $html = $this->get('/security')->assertOk()->getContent();

        $this->assertStringContainsString('Attack severity', $html);
        $this->assertStringContainsString('reaching the application', $html,
            'the card no longer says how many attempts reached the app');

        $at = fn (string $needle) => strpos($html, $needle);

        // "Most targeted pages" and "Busiest source addresses" were dropped
        // from this page; what is left below severity is the attack breakdown.
        foreach (['Attempts by type', 'Failed sign-ins by reason'] as $later) {
            $this->assertLessThan($at($later), $at('Attack severity'),
                "severity no longer comes before \"$later\"");
        }
    }

    /**
     * Every row holds exactly as many panels as its own class declares.
     *
     * This used to assert "two", because every row was a .dash-split and a
     * third panel dropped into one wrapped onto a half-width second row,
     * looking as though something had come loose. The page now has rows of
     * one, two and three, so the constant is wrong -- but the failure it
     * guarded against is not: a panel added to a row without widening the
     * grid still wraps, and still looks broken.
     *
     * So the rule is now relative. `ds-3` must hold three, `ds-2` two, a bare
     * `ds-row` one; `ds-1-2` and `ds-2-1` are two columns of unequal width.
     * The row declares its shape and the test holds it to it, which keeps
     * working whatever the layout becomes next.
     */
    public function test_every_row_holds_the_panels_its_grid_declares(): void
    {
        $this->actingAs($this->makeUser('system-admin'));
        session(['otp_verified' => true]);

        $html = $this->get('/security')->assertOk()->getContent();

        $rows = $this->rowCellCounts($html);

        $this->assertNotEmpty($rows, 'the dashboard has no rows at all');

        foreach ($rows as $number => ['class' => $class, 'declared' => $declared, 'panels' => $panels]) {
            $this->assertSame($declared, $panels, sprintf(
                'row %d (%s) declares %d column(s) but holds %d panel(s), so one wraps',
                $number + 1, $class, $declared, $panels));
        }
    }

    /**
     * The stacked column has to carry the row height the way a single frame
     * does, or its panels sit at their natural heights and leave a gap under
     * the last one while the tall panel beside it runs on past them.
     */
    public function test_the_stacked_column_stretches_to_the_row(): void
    {
        $css = preg_replace('/\s+/', '', file_get_contents(public_path('css/app.css')));

        $this->assertMatchesRegularExpression('/\.dash-split\{[^}]*align-items:stretch/', $css);
        $this->assertMatchesRegularExpression('/\.dash-col>\.dash-frame\{[^}]*flex:1 1 auto/',
            preg_replace('/flex:1(\s*)1(\s*)auto/', 'flex:1 1 auto', $css),
            'the stacked panels do not take up the slack, so the column ends short');
    }

    /** How many columns each row's class declares. */
    private const COLUMNS = ['ds-3' => 3, 'ds-2' => 2, 'ds-1-2' => 2, 'ds-2-1' => 2];

    /**
     * Each row's declared column count against the panels actually in it.
     *
     * Counted by walking div depth rather than by pattern, because a row and
     * the panels inside it are the same tag: a regex cannot tell a row's own
     * cells from the frames nested deeper within one of them.
     *
     * @return list<array{class:string,declared:int,panels:int}>
     */
    private function rowCellCounts(string $html): array
    {
        preg_match_all('#<div\b[^>]*>|</div>#', $html, $m, PREG_OFFSET_CAPTURE);

        $rows = [];
        $depth = null;
        $level = 0;

        foreach ($m[0] as [$tag, $at]) {
            $opening = $tag !== '</div>';

            if ($opening && $depth === null && preg_match('/class="(ds-row[^"]*)"/', $tag, $c)) {
                $depth = $level;          // the row itself
                $declared = 1;
                foreach (self::COLUMNS as $modifier => $count) {
                    if (str_contains($c[1], $modifier)) {
                        $declared = $count;
                        break;
                    }
                }
                $rows[] = ['class' => $c[1], 'declared' => $declared, 'panels' => 0];
            } elseif ($opening && $depth !== null && $level === $depth + 1
                && preg_match('/class="(dash-frame|ds-col)"/', $tag)) {
                // A .ds-col is one cell holding a stack of panels, so it
                // counts once -- the same way the old .dash-col did. Counting
                // the frames inside it instead would report a two-column row
                // as holding three.
                $rows[array_key_last($rows)]['panels']++;
            }

            $level += $opening ? 1 : -1;

            if ($depth !== null && $level === $depth) {
                $depth = null;            // the row closed
            }
        }

        return $rows;
    }

    public function test_a_quiet_week_says_so_rather_than_drawing_empty_bars(): void
    {
        $this->actingAs($this->makeUser('system-admin'));
        session(['otp_verified' => true]);

        $html = $this->get('/security')->assertOk()->getContent();

        $this->assertStringContainsString('Nothing detected in the last 7 days.', $html);
    }
}
