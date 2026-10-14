<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The System Administrator's only dashboard.
 *
 * Its two canvases are gone: every chart here is HTML, CSS and inline SVG now,
 * like the leave dashboards. That retires the bug they had — charts are
 * configured system-wide with `maintainAspectRatio = false` (public/js/app.js),
 * so a responsive canvas takes 100% of its parent's height, and a parent sized
 * by its own contents grew on every resize tick, without limit. The guard
 * against a canvas reappearing anywhere in the system is kept at the foot of
 * this file.
 */
class SecurityDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
    }

    private function asSysadmin(): self
    {
        $this->actingAs($this->makeUser('system-admin'));
        session(['otp_verified' => true]);

        return $this;
    }

    public function test_only_privileged_roles_reach_the_security_dashboard(): void
    {
        foreach (['employee', 'hr', 'mayor'] as $role) {
            $this->actingAs($this->makeUser($role));
            session(['otp_verified' => true]);
            $this->get('/security')->assertForbidden();
        }

        $this->asSysadmin()->get('/security')->assertOk();
    }

    public function test_the_screen_draws_no_canvas_at_all(): void
    {
        $html = $this->asSysadmin()->get('/security')->assertOk()->getContent();

        $this->assertStringNotContainsString('<canvas', $html);

        // Two forms now, chosen by what the number is: the seven-day trend is
        // a line, and the rankings are sideways bars. The third -- the column
        // chart -- is gone with the sign-ins panel it shared a partial with,
        // and the trend it used to draw is a line too.
        $this->assertStringContainsString('class="ln"', $html);
        $this->assertStringContainsString('class="hb-f"', $html);
        $this->assertStringNotContainsString('class="vb"', $html,
            'the column chart is back; _vbars was deleted with the sign-ins panel');
    }

    /**
     * The paper names three attacks and the dashboard shows those three, with
     * the stored category under each so the mapping is visible rather than
     * assumed.
     *
     * `xss` and `traversal` are grouped as input manipulation in the summary
     * only — the detector keeps recording them separately and Intrusion Logs
     * keeps showing which, so no forensic detail is lost.
     */
    public function test_the_attack_chart_carries_the_papers_three_and_no_others(): void
    {
        $html = $this->asSysadmin()->get('/security')->assertOk()->getContent();

        foreach (['SQL injection', 'Input manipulation', 'Brute force'] as $label) {
            $this->assertStringContainsString($label, $html);
        }

        // The mapping is no longer printed under each bar -- dropped at the
        // LGU's request. It still has to EXIST, because "Input manipulation"
        // means nothing unless something says which detectors it covers, so
        // the assertion moves from the screen to the source of truth.
        $map = (new \ReflectionClass(\App\Services\Security\SecurityDashboardService::class))
            ->getConstant('ATTACK_TYPES');
        $this->assertSame('xss + traversal', $map['input']['source']);
        $this->assertSame('accounts locked', $map['brute']['source']);
        $this->assertStringNotContainsString('xss + traversal', $html,
            'the per-bar caption is back on the panel');

        // Exactly three rows in that chart. Order is by count, so the set is
        // what is fixed, not the sequence.
        preg_match_all('/data-series="([a-z]+)"/', $html, $series);
        $found = array_values(array_unique($series[1]));
        sort($found);
        $this->assertSame(['brute', 'input', 'sqli'], $found);
    }

    /** Counts lockouts, not attempts — and says so. */
    public function test_brute_force_counts_the_lockouts_the_threshold_wrote(): void
    {
        \App\Models\IntrusionLog::create([
            'category' => 'auth_fail', 'severity' => 'high', 'route' => 'login',
            'method' => 'POST', 'matched_rule' => 'lockout_threshold',
            'ip' => '192.168.1.42',
        ]);
        \App\Models\IntrusionLog::create([
            'category' => 'xss', 'severity' => 'high', 'route' => 'employees/search',
            'method' => 'GET', 'ip' => '192.168.1.87',
        ]);

        $rows = collect(app(\App\Services\Security\SecurityDashboardService::class)->attacksByType());

        $this->assertSame(1, $rows->firstWhere('key', 'brute')['value']);
        $this->assertSame(1, $rows->firstWhere('key', 'input')['value']);
        $this->assertSame(0, $rows->firstWhere('key', 'sqli')['value']);
    }

    /** An address at the top raises one question: has it been dealt with. */
    public function test_the_busiest_addresses_say_whether_they_are_still_open(): void
    {
        foreach (['192.168.1.87', '192.168.1.87', '192.168.4.11'] as $ip) {
            \App\Models\IntrusionLog::create([
                'category' => 'sqli', 'severity' => 'high', 'route' => 'login',
                'method' => 'GET', 'ip' => $ip,
            ]);
        }
        \App\Models\BlockedIp::create([
            'ip' => '192.168.1.87', 'reason' => 'auto', 'source' => 'auto', 'active' => true,
        ]);

        $rows = app(\App\Services\Security\SecurityDashboardService::class)->topAttackers();

        $this->assertSame('192.168.1.87', $rows[0]['label']);
        $this->assertSame(2, $rows[0]['value']);
        $this->assertTrue($rows[0]['blocked']);
        $this->assertFalse($rows[1]['blocked']);

        // The panel that drew these is gone from the dashboard -- the
        // addresses are actionable on Blocked IPs, and repeating them here
        // said nothing new. What is asserted is the RANKING, which Blocked IPs
        // and the intruder list are both still built on.
        $this->assertStringNotContainsString('Busiest source addresses',
            $this->asSysadmin()->get('/security')->assertOk()->getContent());
    }

    /**
     * `payload_excerpt` is attacker-controlled text and the most interesting
     * field in that table. It belongs on the detail page, escaped — not on a
     * screen somebody glances at forty times a day.
     */
    public function test_the_attackers_own_text_never_reaches_this_screen(): void
    {
        \App\Models\IntrusionLog::create([
            'category' => 'sqli', 'severity' => 'high', 'route' => 'login', 'method' => 'GET',
            'payload_excerpt' => "CANARY' OR 1=1 --", 'ip' => '192.168.1.87',
        ]);

        $this->assertStringNotContainsString('CANARY',
            $this->asSysadmin()->get('/security')->assertOk()->getContent());
    }

    /**
     * The intrusion trend lived on two pages at once: a bar chart on the plain
     * Dashboard and a line chart here, both looping the last seven days and
     * counting the same table. Only the chart type differed, which is the only
     * reason it never looked like a duplicate. It belongs here.
     */
    public function test_the_intrusion_trend_lives_only_on_the_security_dashboard(): void
    {
        // HR holds leave.requests.view-all and reaches the leave dashboard; the
        // security figures are not theirs and never were.
        $this->actingAs($this->makeUser('hr'));
        session(['otp_verified' => true]);
        $leave = $this->get('/dashboard')->assertOk()->getContent();

        $this->assertStringNotContainsString('chartIntrusions', $leave);
        $this->assertStringNotContainsString('Intrusion attempts', $leave);

        $security = $this->asSysadmin()->get('/security')->assertOk()->getContent();
        $this->assertStringContainsString('Intrusion attempts per day', $security);
    }

    /**
     * A line, and a red one.
     *
     * This asserted bars, on the reasoning that seven discrete daily counts
     * belong as seven columns and that a line dipping to the axis reads as
     * though something happened when nothing did. That is a fair argument and
     * it lost to a better one: what an administrator scans this panel for is
     * the SHAPE of a week -- quiet, quiet, then a climb -- and a shape is what
     * a line carries and seven separate columns do not.
     *
     * Red because on this series a rise is bad news. It is the same --k-bad
     * the Critical grade uses on the dial above, so the page has one red
     * meaning one thing.
     */
    public function test_the_seven_day_trend_is_drawn_as_a_red_line(): void
    {
        $html = $this->asSysadmin()->get('/security')->assertOk()->getContent();

        preg_match('#Intrusion attempts per day.*?</div>\s*</div>#s', $html, $panel);
        $this->assertNotEmpty($panel);
        // A curved <path>, not a <polyline>: the segments are cubic Béziers
        // now, so seven daily counts read as one week rather than seven
        // readings joined with a ruler.
        $this->assertMatchesRegularExpression('/<path class="p1 p1-bad"[^>]*d="M /', $panel[0],
            'the trend is no longer a curved path in the alarm colour');
        $this->assertStringContainsString('ln-prev', $panel[0],
            'the previous week is no longer drawn behind it');

        // Seven points, labelled Mon/Tue/Wed — M/T/W/T/F is ambiguous twice
        // over in five letters.
        preg_match('#<div class="ln-x">(.*?)</div>#s', $html, $labels);
        $this->assertNotEmpty($labels, 'the trend has no day axis');
        preg_match_all('/<span[^>]*>(\w+)<\/span>/', $labels[1], $days);
        $this->assertCount(7, $days[1]);
        $this->assertSame(now()->format('D'), end($days[1]), 'the last point must be today');
    }

    /** Seven days, one query — not one count per day. */
    public function test_the_trend_is_built_without_a_query_per_day(): void
    {
        $this->asSysadmin();

        \DB::enableQueryLog();
        app(\App\Services\Security\SecurityDashboardService::class)->intrusionsByDay();
        $queries = \DB::getQueryLog();
        \DB::disableQueryLog();

        $this->assertCount(1, $queries,
            'seven days of counts is one grouped query, not seven round trips');
    }

    /**
     * The same invariant for every chart in the system, checked against the
     * Blade sources rather than one rendered page — a canvas added to any view
     * without a sized parent is this bug returning somewhere new.
     *
     * A parent counts as sized if it carries an inline height, or one of the
     * wrapper classes below. Those classes are themselves verified against the
     * stylesheet, so the list cannot quietly stop meaning anything.
     */
    public function test_no_view_renders_a_chart_without_a_sized_parent(): void
    {
        $sizingClasses = ['chart-box', 'mix-chart'];

        $css = file_get_contents(public_path('css/app.css'));
        foreach ($sizingClasses as $class) {
            $this->assertMatchesRegularExpression(
                '/\.'.preg_quote($class, '/').'\s*\{[^}]*height\s*:\s*\d/',
                $css,
                ".{$class} is trusted to size a chart but the stylesheet gives it no height"
            );
        }

        $offenders = [];
        foreach ($this->bladeFiles() as $file) {
            $source = file_get_contents($file);
            // dompdf templates carry their own stylesheet and never load Chart.js.
            if (str_contains($source, 'DejaVu Sans') || ! str_contains($source, '<canvas')) {
                continue;
            }

            preg_match_all('/<canvas/', $source, $m, PREG_OFFSET_CAPTURE);
            foreach ($m[0] as [$_, $offset]) {
                $before = substr($source, max(0, $offset - 260), min(260, $offset));

                $sized = preg_match('/height\s*:\s*\d+(px|rem|vh)/', $before) === 1;
                foreach ($sizingClasses as $class) {
                    $sized = $sized || str_contains($before, $class);
                }

                if (! $sized) {
                    $offenders[] = basename(dirname($file)).'/'.basename($file);
                }
            }
        }

        $this->assertSame([], array_values(array_unique($offenders)),
            'these views draw a chart with no fixed-height parent, so it will grow without limit');
    }

    /** @return array<string> every Blade template in the application */
    private function bladeFiles(): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'))
        );
        foreach ($it as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
