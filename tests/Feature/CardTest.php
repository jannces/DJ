<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * What separates a card that looks deliberate from one that looks default.
 *
 * Four things, and this application had none of them by accident: four
 * `.card` blocks had grown up in four sections of app.css, disagreeing on
 * radius and shadow, and the last one won. It declared no shadow at all, so
 * every panel sat flat on the page with a hairline round it.
 *
 * The numbers below are scaled to THIS application. A product card gets 40px
 * of padding and a 24px radius and looks expensive; a panel carrying ninety
 * leave applications gets the same treatment and turns into scrolling. The
 * move being made is from cramped-default to deliberate, not from cramped to
 * marketing.
 */
class CardTest extends TestCase
{
    private string $css;

    protected function setUp(): void
    {
        parent::setUp();
        $this->css = file_get_contents(public_path('css/app.css'));
    }

    /** One definition. The same lottery that deleted the focus ring. */
    public function test_the_card_is_defined_exactly_once(): void
    {
        $this->assertSame(1, preg_match_all('/^\.card\{/m', $this->css),
            'more than one .card block: whichever lands last silently wins');
    }

    /**
     * Two shadows, because one cannot do both jobs.
     *
     * The tight dark layer gives the edge its contrast, so the card separates
     * from the page. The wide soft layer is ambient light, so it sits ABOVE
     * the page rather than on it. A single shadow is either a hard edge or a
     * vague glow.
     */
    public function test_depth_comes_from_two_layers_in_light(): void
    {
        preg_match('/--card-shadow:\s*([^;]+);/s', $this->css, $m);
        $this->assertNotEmpty($m, 'there is no card shadow at all');

        $this->assertSame(2, substr_count($m[1], 'rgb('),
            'the light card has one shadow layer, so it is either flat or floating');
    }

    /**
     * Dark mode keeps the tight layer and drops the ambient one.
     *
     * A soft black glow under a dark card is invisible; the depth there has to
     * come from the border. Copying the light-mode stack over would cost
     * render time for nothing anybody can see.
     */
    public function test_dark_mode_does_not_pretend_a_black_glow_is_visible(): void
    {
        $dark = substr($this->css, strpos($this->css, '[data-bs-theme="dark"]{
  /* Dark backgrounds'));
        preg_match('/--card-shadow:([^;]+);/', $dark, $m);

        $this->assertNotEmpty($m, 'dark mode has no card shadow of its own');
        $this->assertSame(1, substr_count($m[1], 'rgb('));
    }

    /** Spacing that reads as a decision, at a density HR can still work in. */
    public function test_the_spacing_is_deliberate_without_costing_density(): void
    {
        $this->assertMatchesRegularExpression('/\.card-body\{ padding:1\.5rem; \}/', $this->css);
        $this->assertMatchesRegularExpression('/\.card-header\{[^}]*padding:1rem 1\.5rem/s', $this->css);

        // The radius has to match on the card and on the table that fills its
        // top edge, or the corner shows a sliver of the card behind the table.
        $this->assertMatchesRegularExpression('/\.card\{[^}]*border-radius:\.875rem/s', $this->css);
        $this->assertMatchesRegularExpression(
            '/\.card > \.table-responsive:first-child\{ border-top-left-radius:\.875rem/', $this->css);
    }

    /** The title wins the eye by weight and size. */
    public function test_the_header_outranks_what_sits_beside_it(): void
    {
        $this->assertMatchesRegularExpression('/\.card-header\{[^}]*font-weight:650/s', $this->css);
        $this->assertMatchesRegularExpression(
            '/\.card-header \.lf-ref[^{]*\{[^}]*font-weight:500/s', $this->css);
    }

    /**
     * The description is NOT dropped to 55% opacity.
     *
     * That is the figure the reference gives, and on this system it would put
     * secondary text at 3.96:1 -- under the 4.5:1 that body text has to hold.
     * --muted measures 5.4:1 on white, and the hierarchy is carried by weight
     * and size instead, which cost nothing in legibility.
     */
    public function test_secondary_text_stays_readable(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/\.card[^{]*\{[^}]*opacity:\s*0?\.5[0-9]?/s', $this->css,
            'card text is faded below the readable floor');

        $this->assertGreaterThanOrEqual(4.5, $this->contrast('#6d6780', '#ffffff'));
        $this->assertGreaterThanOrEqual(4.5, $this->contrast('#a49bbd', '#1a1330'));
    }

    /**
     * No hover lift, because nothing here is clickable.
     *
     * A lift says "click me". Every card in this application is a panel -- a
     * table, a form section, a chart -- and a card that rises under the
     * pointer and then does nothing is a promise the interface cannot keep.
     *
     * If a clickable card is ever added, this test fails and says so: the
     * decision to leave the lift out was made about the cards that exist, and
     * it should be revisited the day one of them becomes a link.
     */
    public function test_no_card_is_clickable_so_none_of_them_lift(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/\.card:hover\{[^}]*transform:/s', $this->css,
            'a card lifts on hover but leads nowhere');

        $clickable = [];
        foreach ($this->views() as $file) {
            $html = file_get_contents($file);
            if (preg_match('/<a\b[^>]*class="[^"]*\bcard\b/', $html)) {
                $clickable[] = basename($file);
            }
        }

        $this->assertSame([], $clickable,
            "a card is now a link, so the no-hover-lift decision needs revisiting:\n"
            .implode("\n", $clickable));
    }

    /** @return array<string> */
    private function views(): array
    {
        $files = [];
        $dir = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views')));

        foreach ($dir as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function contrast(string $a, string $b): float
    {
        $l = fn (string $hex): float => (function (array $c): float {
            $f = fn (float $v): float => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;

            return 0.2126 * $f($c[0]) + 0.7152 * $f($c[1]) + 0.0722 * $f($c[2]);
        })(array_map(fn ($p) => hexdec($p) / 255, str_split(ltrim($hex, '#'), 2)));

        $la = $l($a);
        $lb = $l($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }
}
