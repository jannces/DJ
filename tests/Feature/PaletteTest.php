<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The decisions behind the interface's colour, and the arithmetic under them.
 *
 * Most of this file exists because the numbers are not obvious by eye. A fill
 * that looks bright enough to carry white text usually is not, and the two
 * places that caught me out -- amber and green -- are pinned here so the next
 * person to brighten them finds out immediately rather than at the defence.
 */
class PaletteTest extends TestCase
{
    private string $css;

    protected function setUp(): void
    {
        parent::setUp();
        $this->css = file_get_contents(public_path('css/app.css'));
    }

    /**
     * The value a browser would actually use.
     *
     * This stylesheet declares its tokens in several :root blocks and the last
     * one wins, so the test has to walk them in order rather than read the
     * first or the nearest -- which is the same trap that let three of these
     * colours disagree with each other in the first place.
     */
    private function token(string $name, string $scope = 'light'): string
    {
        preg_match_all('/(:root[^{]*|\[data-bs-theme[^{]*)\{([^}]*)\}/s', $this->css, $blocks,
            PREG_SET_ORDER);

        $value = null;
        foreach ($blocks as [, $selector, $body]) {
            $isDark = str_contains($selector, 'data-bs-theme="dark"');
            if ($isDark !== ($scope === 'dark')) {
                continue;
            }
            if (preg_match_all('/'.preg_quote($name, '/').':\s*(#[0-9A-Fa-f]{3,8})/', $body, $m)) {
                $value = end($m[1]);
            }
        }

        $this->assertNotNull($value, "$name is not defined in the $scope palette");

        return $value;
    }

    private function contrast(string $a, string $b): float
    {
        $l = function (string $hex): float {
            $hex = ltrim($hex, '#');
            if (strlen($hex) === 3) {
                $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
            }
            $c = array_map(fn ($p) => hexdec($p) / 255, str_split($hex, 2));
            $f = fn (float $v): float => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;

            return 0.2126 * $f($c[0]) + 0.7152 * $f($c[1]) + 0.0722 * $f($c[2]);
        };
        $la = $l($a);
        $lb = $l($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /**
     * The figure on every tile clears the floor that applies to it.
     *
     * The value is 2rem at weight 700, which is large text, and large text
     * needs 3:1 rather than 4.5:1. That distinction is the whole reason this
     * row can stay as bright as it is.
     */
    public function test_the_figure_reads_on_every_tile(): void
    {
        foreach (['--tile-1', '--tile-2', '--tile-3', '--tile-4'] as $t) {
            $fill = $this->token($t);
            $this->assertGreaterThanOrEqual(3.0, $this->contrast('#ffffff', $fill),
                "$t is $fill, where even the large figure falls below the floor");
        }

        // ...and it really is large text, or the floor above is the wrong one.
        $this->assertMatchesRegularExpression(
            '/\.dash \.kpi \.kpi-v\{[^}]*font-weight:700;[^}]*font-size:2rem/s', $this->css);
    }

    /**
     * A trade was made on two tiles, and it is recorded here rather than left
     * to be discovered.
     *
     * Blue and pink are dark enough to carry white at 4.5:1, so their labels
     * and sub-lines are fully compliant. Amber and green are not: those hues
     * are naturally light, and holding 4.5:1 would push them to #A36814 and
     * #10844E -- bronze and forest. The brighter values kept here clear 3:1,
     * which covers the figure, and leave the small lines between 3:1 and
     * 4.5:1.
     *
     * That was a deliberate choice about how the dashboard should look, taken
     * with the measurements on the table. This test holds the line at 3:1 so
     * the trade cannot quietly get worse.
     */
    public function test_the_two_light_hues_trade_small_text_contrast(): void
    {
        $compliant = ['--tile-1', '--tile-4'];
        foreach ($compliant as $t) {
            $this->assertGreaterThanOrEqual(4.5, $this->contrast('#ffffff', $this->token($t)),
                "$t used to carry white text at full strength and no longer does");
        }

        foreach (['--tile-2', '--tile-3'] as $t) {
            $ratio = $this->contrast('#ffffff', $this->token($t));
            $this->assertGreaterThanOrEqual(3.0, $ratio,
                "$t has been brightened past the point where even the figure reads");
            $this->assertLessThan(4.5, $ratio,
                "$t now clears 4.5:1, so this documented trade no longer exists and the "
                ."comment above it is stale -- move it into the compliant list");
        }
    }

    /**
     * Dark mode makes no such trade.
     *
     * The fills there are already deeper to sit in a dark shell, so all four
     * clear 4.5:1 without costing anything. Worth pinning: it means the light
     * palette is the only place the compromise lives.
     */
    public function test_the_dark_tiles_need_no_trade(): void
    {
        foreach (['--tile-1', '--tile-2', '--tile-3', '--tile-4'] as $t) {
            $this->assertGreaterThanOrEqual(4.5,
                $this->contrast('#ffffff', $this->token($t, 'dark')), $t.' in dark mode');
        }
    }

    /**
     * The active nav item is a FILL, so it needs an ink of its own.
     *
     * It used to be a pale tint with coloured text, and the rule that painted
     * it that way sat further down the file than the tokens did. Reading the
     * ink from --side-text-hi would put near-black on a solid blue pill.
     */
    public function test_the_active_pill_states_its_own_ink(): void
    {
        $this->assertGreaterThanOrEqual(4.5,
            $this->contrast($this->token('--side-active-ink'), $this->token('--side-active')));

        $this->assertMatchesRegularExpression(
            '/\.lms-nav \.nav-link\.active\{[^}]*color:var\(--side-active-ink\)/s', $this->css,
            'the active item takes its ink from somewhere other than the pill token');
    }

    /** Nav labels and section headings still read on the rail's own ground. */
    public function test_the_rail_is_legible_against_itself(): void
    {
        $rail = $this->token('--side-bg');

        $this->assertGreaterThanOrEqual(4.5, $this->contrast($this->token('--side-text'), $rail),
            'nav labels are washed out on the rail');
        $this->assertGreaterThanOrEqual(3.0, $this->contrast($this->token('--side-disc-ink'), $this->token('--side-disc')),
            'the icon in its disc is below the floor for a graphical object');
    }

    /**
     * The OS supplies its own face first.
     *
     * San Francisco cannot be self-hosted -- Apple licenses it to Apple
     * platforms -- so the only way this system can use it is to ask the OS.
     * Inter used to sit ahead of -apple-system in the stack, which meant a Mac
     * or an iPhone never rendered SF at all, and Inter (drawn as an SF-alike)
     * still catches everything else.
     */
    public function test_apple_devices_get_their_own_face(): void
    {
        preg_match('/--font-sans:\s*([^;]+);/', $this->css, $m);
        $this->assertNotEmpty($m);

        $stack = array_map('trim', explode(',', $m[1]));
        $this->assertSame('-apple-system', $stack[0],
            'the system face is not asked for first, so Apple devices never see SF');
        $this->assertContains("'Inter'", $stack,
            'nothing self-hosted catches the platforms without a system UI face');
        $this->assertLessThan(array_search("'Inter'", $stack), array_search('-apple-system', $stack));
    }

    /**
     * The user chip follows the accent.
     *
     * It was painted from the brand ramp rather than from --primary, so it
     * stayed violet through every palette change made this session and was the
     * one visibly wrong thing left on the page. Not a raw hex -- a different
     * token family, which is harder to spot and behaves the same way.
     */
    public function test_the_avatar_follows_the_accent_not_the_old_ramp(): void
    {
        preg_match('/\n\.avatar \{(.*?)\}/s', $this->css, $m);
        $this->assertNotEmpty($m, 'the avatar has no rule');

        $this->assertStringContainsString('var(--primary)', $m[1]);
        $this->assertStringNotContainsString('--brand-500', $m[1],
            'the user chip is painted from the brand ramp again, so it will survive the next palette change');
    }
}
