<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Security Dashboard, and the layout invariant its charts depend on.
 *
 * Charts are configured system-wide with `maintainAspectRatio = false`
 * (public/js/app.js), so a responsive canvas sizes itself to 100% of its
 * parent's height. If that parent has no height of its own — a bare card body,
 * say — the two size each other and the chart grows on every resize tick,
 * without limit. Both charts on this page did exactly that.
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

    public function test_both_charts_sit_in_a_sized_container(): void
    {
        $html = $this->asSysadmin()->get('/security')->assertOk()->getContent();

        // Each canvas must be wrapped, or it grows forever.
        foreach (['trend', 'cats'] as $id) {
            $this->assertMatchesRegularExpression(
                '/class="chart-box[^"]*"[^>]*>\s*<canvas id="'.$id.'"/',
                $html,
                "the #{$id} chart has no sized container and will grow without limit"
            );
        }

        // The height attribute is overwritten by a responsive chart; leaving it
        // in suggests it does something.
        $this->assertStringNotContainsString('<canvas id="trend" height=', $html);
        $this->assertStringNotContainsString('<canvas id="cats" height=', $html);
    }

    /**
     * The intrusion trend lived on two pages at once: a bar chart on the plain
     * Dashboard and a line chart here, both looping the last seven days and
     * counting the same table. Only the chart type differed, which is the only
     * reason it never looked like a duplicate. It belongs here.
     */
    public function test_the_intrusion_trend_lives_only_on_the_security_dashboard(): void
    {
        $dashboard = $this->asSysadmin()->get('/dashboard')->assertOk()->getContent();
        $this->assertStringNotContainsString('chartIntrusions', $dashboard);
        $this->assertStringNotContainsString('Intrusion attempts', $dashboard);

        $security = $this->get('/security')->assertOk()->getContent();
        $this->assertStringContainsString('id="trend"', $security);
        $this->assertStringContainsString('Attacks (last 7 days)', $security);
    }

    /**
     * Bars, not a line. Attacks per day are seven discrete counts, so a day
     * with none belongs as an absent bar — a line dipping to the axis and back
     * reads as though something happened when nothing did.
     */
    public function test_the_seven_day_trend_is_drawn_as_bars(): void
    {
        $html = $this->asSysadmin()->get('/security')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            "/getElementById\('trend'\).*?type:\s*'bar'/s",
            $html,
            'the 7-day trend should be a bar chart'
        );
    }

    /** Seven days, one query — not one count per day. */
    public function test_the_trend_is_built_without_a_query_per_day(): void
    {
        $this->asSysadmin();

        \DB::enableQueryLog();
        app(\App\Services\DashboardService::class)->intrusionsByDay();
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
