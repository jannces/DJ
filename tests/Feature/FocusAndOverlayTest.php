<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Where focus goes, and what an overlay is allowed to be.
 *
 * Two rules, both about not blocking people:
 *
 *   · A focus ring has to be THICK enough, OFFSET enough and -- the one that
 *     is usually missed -- CONTRASTY enough, on both themes. Removing an
 *     outline without replacing it ships an accessibility failure that nobody
 *     using a mouse will ever notice.
 *
 *   · An overlay is chosen by one question: does this block the user? A
 *     blocking decision gets a modal and a blurred backdrop. Everything else
 *     -- a two-field record panel, a menu -- gets something lighter, and on a
 *     phone it becomes a bottom sheet.
 */
class FocusAndOverlayTest extends TestCase
{
    use RefreshDatabase;

    private string $css;

    protected function setUp(): void
    {
        parent::setUp();
        $this->css = file_get_contents(public_path('css/app.css'));
    }

    // ----------------------------------------------------------------- focus

    /**
     * The ring is 2px thick, 2px clear of the element, and only for keyboards.
     */
    public function test_the_focus_ring_has_thickness_offset_and_is_keyboard_only(): void
    {
        $this->assertMatchesRegularExpression(
            '/^:focus-visible\{\s*outline:2px solid var\(--focus-ring\);\s*outline-offset:2px;/m',
            $this->css);

        // :focus would fire on a mouse click too, and a ring on every click is
        // noise that teaches people to ignore the one that matters.
        $this->assertDoesNotMatchRegularExpression('/^:focus\{/m', $this->css);
    }

    /**
     * And it can actually be seen -- in BOTH themes.
     *
     * It could not before. The ring was gold at 70% opacity, which composites
     * to #e6c26a on a white card: 1.7:1. It looked right in dark mode, which
     * is where it was designed, and a keyboard user in light mode had no
     * visible focus at all.
     */
    public function test_the_focus_ring_clears_three_to_one_on_both_themes(): void
    {
        $this->assertMatchesRegularExpression('/:root\{ --focus-ring:#191626; \}/', $this->css);
        $this->assertMatchesRegularExpression('/--focus-ring:#f1eef9/', $this->css);

        $this->assertGreaterThanOrEqual(3.0, $this->contrast('#191626', '#f7f6fb'));
        $this->assertGreaterThanOrEqual(3.0, $this->contrast('#f1eef9', '#120e24'));
    }

    /**
     * No outline is removed without something replacing it.
     *
     * `outline:none` is not a style choice on its own -- it is an
     * accessibility failure unless a ring arrives in its place.
     */
    public function test_every_removed_outline_has_a_replacement(): void
    {
        preg_match_all('/([^{}]*)\{([^}]*outline:\s*(?:none|0)[^}]*)\}/', $this->css, $m, PREG_SET_ORDER);

        $bare = [];
        foreach ($m as [$whole, $selector, $body]) {
            // A ring drawn some other way, in the same rule or in a sibling
            // rule for the same selector, counts as the replacement.
            if (str_contains($body, 'box-shadow') && ! str_contains($body, 'box-shadow:none')) {
                continue;
            }
            $name = trim(preg_replace('/\s+/', ' ', $selector));
            if (preg_match('/\.(auth-input|otp) input/', $name)) {
                continue;  // the wrapper takes the ring; see .auth-input:focus-within
            }
            $bare[] = $name;
        }

        $this->assertSame([], $bare,
            "an outline is removed with nothing in its place:\n".implode("\n", $bare));
    }

    /** The skip link is the first focusable thing on the page, and hidden until then. */
    public function test_the_skip_link_is_first_and_invisible_until_focused(): void
    {
        $this->seedCore();
        $user = $this->makeUser('hr');
        $user->update(['must_change_password' => false]);
        $this->actingAs($user->fresh());
        session(['otp_verified' => true]);

        $html = $this->get('/positions')->assertOk()->getContent();

        $body = substr($html, strpos($html, '<body>'));
        $this->assertStringContainsString('class="skip-link', $body, 'there is no skip link');

        // Nothing focusable may come before it, or it is not a skip link.
        // Sliced at the element's own opening tag, not at its class attribute,
        // which sits a few characters inside it.
        $skip = strpos($body, '<a class="skip-link');
        $this->assertNotFalse($skip, 'the skip link is not a plain <a> at the top of the body');
        $before = substr($body, 0, $skip);
        $this->assertDoesNotMatchRegularExpression('/<(a|button|input|select|textarea)\b/', $before,
            'something focusable sits in front of the skip link');

        // It has somewhere to go, and that target can receive focus.
        $this->assertStringContainsString('href="#lms-content"', $body);
        $this->assertStringContainsString('id="lms-content" tabindex="-1"', $body);

        // Clipped, not display:none -- the latter takes it out of the tab
        // order, leaving a skip link nobody can reach.
        $this->assertMatchesRegularExpression('/\.skip-link\{[^}]*clip-path:inset\(50%\)/s', $this->css);
        $this->assertDoesNotMatchRegularExpression('/\.skip-link\{[^}]*display:none/s', $this->css);
        $this->assertMatchesRegularExpression('/\.skip-link:focus-visible\{[^}]*clip-path:none/s', $this->css);
    }

    // --------------------------------------------------------------- overlays

    /**
     * Blur means stop. It is rationed to the one overlay that is a stop.
     *
     * A blurred backdrop only reads as "answer this first" while it stays
     * rare; spending it on a routine record panel spends the signal.
     */
    public function test_only_the_blocking_confirmation_blurs_what_is_behind_it(): void
    {
        preg_match_all('/([^{}]+)\{[^}]*backdrop-filter:[^}]*blur[^}]*\}/', $this->css, $m);

        $backdrops = [];
        foreach ($m[1] as $selector) {
            $name = trim(preg_replace('/\s+/', ' ', $selector));

            // Only overlays count. A translucent topbar or an auth card blurs
            // the wallpaper behind ITSELF; it is a surface, not a scrim, and
            // it never covers the page to ask a question.
            if (! preg_match('/backdrop|scrim|overlay|-bg\b|page-loader/', $name)) {
                continue;
            }
            $backdrops[] = $name;
        }

        $this->assertNotEmpty($backdrops, 'no blurred overlay found at all, so this proves nothing');

        foreach ($backdrops as $name) {
            $this->assertStringContainsString('lms-ask', $name,
                'something other than the blocking confirmation blurs the page: '.$name);
        }
    }

    /**
     * On a phone a record panel is a bottom sheet.
     *
     * It does not block anybody -- it is two or three fields opened on purpose
     * and walked away from at will -- so it takes the shape that says so:
     * up from the bottom edge, sized to its content, with the list still
     * visible above it and the buttons within thumb reach.
     */
    public function test_a_record_panel_becomes_a_bottom_sheet_on_a_phone(): void
    {
        $mobile = $this->mediaBlock('@media (max-width:640px)', 'modal-dialog');

        $this->assertStringContainsString('bottom:0', $mobile);
        $this->assertStringContainsString('border-radius:1rem 1rem 0 0', $mobile,
            'the sheet has no top corners, so it reads as a stuck dialog');
        $this->assertStringContainsString('max-height:88vh', $mobile,
            'the sheet covers the whole screen and loses the context behind it');
        $this->assertMatchesRegularExpression('/\.modal-header::before\{[^}]*width:2\.25rem/s', $mobile,
            'there is no grab handle marking the top edge');
    }

    /** A modal is never how somebody navigates. */
    public function test_no_overlay_carries_navigation(): void
    {
        foreach ($this->views() as $file) {
            $html = file_get_contents($file);
            $at = 0;
            while (($start = strpos($html, 'class="modal fade"', $at)) !== false) {
                $end = strpos($html, '</div>', $start);
                $modal = substr($html, $start, ($end ?: strlen($html)) - $start + 6000);
                $modal = substr($modal, 0, strpos($modal, '</form>') ?: strlen($modal));

                $this->assertStringNotContainsString('nav-link', $modal,
                    basename($file).' buries navigation inside a blocking overlay');
                $at = $start + 1;
            }
        }

        $this->assertTrue(true);
    }

    // ------------------------------------------------------------------ utils

    private function mediaBlock(string $query, string $mustContain): string
    {
        $start = strpos($this->css, $query);
        $this->assertNotFalse($start, "no $query block");

        // Walk the braces so a nested rule does not end the block early.
        $i = strpos($this->css, '{', $start);
        $depth = 0;
        for ($j = $i; $j < strlen($this->css); $j++) {
            if ($this->css[$j] === '{') {
                $depth++;
            }
            if ($this->css[$j] === '}') {
                $depth--;
            }
            if ($depth === 0) {
                break;
            }
        }
        $block = substr($this->css, $i, $j - $i);

        if (! str_contains($block, $mustContain)) {
            // More than one block matches that query; find the right one.
            $next = strpos($this->css, $query, $start + 1);
            $this->assertNotFalse($next, "no $query block containing $mustContain");
            $rest = substr($this->css, $next);

            return $rest;
        }

        return $block;
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
