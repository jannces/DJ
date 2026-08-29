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

    public function test_the_panel_is_on_the_dashboard_beside_the_other_two(): void
    {
        $this->attack('203.0.113.9');
        $this->actingAs($this->makeUser('system-admin'));
        session(['otp_verified' => true]);

        $html = $this->get('/security')->assertOk()->getContent();

        $this->assertStringContainsString('Attack severity', $html);
        $this->assertStringContainsString('Reached the app', $html);

        // The two it runs alongside are stacked in one column, it is the other.
        $this->assertMatchesRegularExpression('#<div class="dash-col">#', $html);
        $column = substr($html, strpos($html, '<div class="dash-col">'));
        $this->assertLessThan(strpos($column, 'Attack severity'), strpos($column, 'Attempts by type'));
        $this->assertLessThan(strpos($column, 'Attack severity'), strpos($column, 'Most targeted pages'));
    }

    /**
     * Every panel on this page is in a row that can hold it.
     *
     * .dash-split is a two-column grid, so a third panel dropped into one
     * wraps onto a half-width second row and the page reads as though
     * something came loose. The stacked column and the tall panel beside it
     * are one row of two, like every other row here.
     */
    public function test_the_dashboard_rows_hold_two_panels_each(): void
    {
        $this->actingAs($this->makeUser('system-admin'));
        session(['otp_verified' => true]);

        $html = $this->get('/security')->assertOk()->getContent();

        $rows = $this->rowCellCounts($html);

        $this->assertNotEmpty($rows, 'the dashboard has no rows at all');

        foreach ($rows as $number => $cells) {
            $this->assertSame(2, $cells,
                'row '.($number + 1).' holds '.$cells.' panels in a two-column grid');
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

    /**
     * How many cells each .dash-split row holds.
     *
     * Counted by walking div depth rather than by pattern, because a row's
     * cells and the panels nested inside one of them are the same tag: a
     * regex cannot tell the column's two panels from the row's two cells.
     *
     * @return array<int,int>
     */
    private function rowCellCounts(string $html): array
    {
        preg_match_all('#<div\b[^>]*>|</div>#', $html, $m, PREG_OFFSET_CAPTURE);

        $rows = [];
        $depth = null;
        $level = 0;

        foreach ($m[0] as [$tag, $at]) {
            $opening = $tag !== '</div>';

            if ($opening && $depth === null && str_contains($tag, 'class="dash-split"')) {
                $depth = $level;          // the row itself
                $rows[] = 0;
            } elseif ($opening && $depth !== null && $level === $depth + 1
                && preg_match('/class="dash-(frame|col)"/', $tag)) {
                $rows[array_key_last($rows)]++;
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

        $this->assertStringContainsString('No attacks detected in the last 7 days.', $html);
    }
}
