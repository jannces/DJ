<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sign-in screen carries two photographs of the municipality itself.
 *
 * They are ordinary files under public/, referenced from the stylesheet rather
 * than from a template, which means nothing fails loudly if one goes missing —
 * the panel just renders empty and nobody notices until it is on a projector.
 * These assertions are the only thing standing between that and a defence.
 */
class SignInScreenTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string,int> file => smallest size that is plausibly the real image */
    private const ASSETS = [
        'img/alicia-hall.jpg' => 100_000,
        'img/one-alicia.png' => 20_000,
    ];

    private function css(): string
    {
        return file_get_contents(public_path('css/app.css'));
    }

    public function test_both_photographs_are_present_and_are_not_placeholders(): void
    {
        foreach (self::ASSETS as $path => $floor) {
            $this->assertFileExists(public_path($path));
            $this->assertGreaterThan($floor, filesize(public_path($path)),
                "{$path} is too small to be the real image");
        }
    }

    public function test_the_stylesheet_points_at_the_files_that_are_actually_there(): void
    {
        $css = $this->css();

        foreach (array_keys(self::ASSETS) as $path) {
            $this->assertStringContainsString('../'.$path, $css,
                "nothing in the stylesheet uses {$path}");
        }

        // The reference is relative to public/css/, so a stray leading slash or
        // a missing ../ resolves to a URL that does not exist on the LAN build.
        preg_match_all("#url\(['\"]?(\.\./img/[^'\")]+)#", $css, $matches);
        foreach ($matches[1] as $reference) {
            $this->assertFileExists(
                public_path('css/'.$reference),
                "the stylesheet asks for {$reference} and it is not there"
            );
        }
    }

    /**
     * The hall is bright — pale sky across the top, cream walls through the
     * middle — and the panel is white text. Without the wash in front of it the
     * heading sits on cloud at roughly 1.4:1.
     */
    public function test_the_hall_sits_behind_a_wash_rather_than_on_its_own(): void
    {
        preg_match('/\.auth-aside\s*\{([^}]*alicia-hall[^}]*)\}/s', $this->css(), $m);
        $this->assertNotEmpty($m, '.auth-aside does not use the photograph');

        $this->assertStringContainsString('linear-gradient', $m[1],
            'the photograph needs an overlay in front of it or the text is unreadable');

        preg_match_all('/rgba\([^)]*?,\s*\.(\d+)\s*\)/', $m[1], $alphas);
        $this->assertNotEmpty($alphas[1], 'the overlay has no alpha to check');
        foreach ($alphas[1] as $alpha) {
            $this->assertGreaterThanOrEqual(85, (int) str_pad($alpha, 2, '0'),
                'an overlay below .85 lets the sky through and the heading fails contrast');
        }
    }

    /**
     * The artwork is drawn on white and its own outlines are that same white,
     * so it cannot be cut out. `multiply` is what drops the white against the
     * light panel; without it the mark arrives as a white rectangle.
     */
    public function test_the_mark_is_blended_rather_than_pasted_on(): void
    {
        preg_match('/\.auth-main::before\s*\{([^}]*)\}/s', $this->css(), $m);
        $this->assertNotEmpty($m, '.auth-main::before is gone');

        $this->assertStringContainsString('one-alicia', $m[1]);
        $this->assertStringContainsString('mix-blend-mode:multiply', str_replace(' ', '', $m[1]));
        $this->assertStringContainsString('pointer-events:none', str_replace(' ', '', $m[1]),
            'the layer covers the whole panel and must not swallow clicks on the form');
    }

    /** Multiply on a dark ground erases the mark, so dark theme needs its own. */
    public function test_dark_theme_gets_its_own_treatment(): void
    {
        $this->assertMatchesRegularExpression(
            '/\[data-bs-theme="dark"\]\s*\.auth-main::before\s*\{[^}]*opacity/',
            $this->css(),
            'without this the mark is invisible for anyone signed in with dark theme'
        );
    }

    /**
     * The panel text was three greens from an earlier palette. Over a
     * photograph they read as muddy and the fine print drops under 4.5:1.
     */
    public function test_the_panel_text_is_no_longer_the_old_green_palette(): void
    {
        $blade = file_get_contents(resource_path('views/layouts/guest.blade.php'));

        foreach (['#9fc3ac', '#c5d8cc', '#8fb69f'] as $green) {
            $this->assertStringNotContainsStringIgnoringCase($green, $blade,
                "{$green} is left over from the green palette and does not survive the photograph");
        }
    }

    public function test_the_sign_in_page_still_renders_with_both_panels(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('auth-aside', $html);
        $this->assertStringContainsString('auth-main', $html);
        $this->assertStringContainsString('Local Government Unit', $html);
    }

    /**
     * The panel is hidden below 900px, which is also what stops a phone on
     * mobile data from downloading a quarter-megabyte photograph it will never
     * show.
     */
    public function test_the_photograph_is_not_sent_to_phones(): void
    {
        $this->assertMatchesRegularExpression(
            '/@media\(max-width:900px\)\{.*?\.auth-aside\{display:none/',
            preg_replace('/\s+/', '', $this->css()),
            'the aside must stay display:none on small screens'
        );
    }
}
