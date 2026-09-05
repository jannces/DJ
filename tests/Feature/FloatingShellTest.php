<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The rail and the topbar float, and the drawer still works.
 *
 * Those two facts are in tension, which is the only reason this file exists.
 * The rail hides itself in two situations -- the collapse toggle on a desktop
 * and the off-canvas drawer below 1100px -- and both do it by pulling
 * `margin-left` negative by the rail's own width. Insetting the rail means
 * setting a margin, so an unscoped `margin` shorthand would quietly beat both
 * of them and leave a menu that cannot be closed on a phone.
 */
class FloatingShellTest extends TestCase
{
    private string $css;

    protected function setUp(): void
    {
        parent::setUp();
        $this->css = file_get_contents(public_path('css/app.css'));
    }

    /** The body of the wide-screen block the float lives in. */
    private function wideBlock(): string
    {
        $needle = '@media (min-width:1101px){';
        $open = strpos($this->css, $needle);
        $this->assertNotFalse($open, 'the floating shell has no wide-screen block');

        $i = $open + strlen($needle) - 1;
        $depth = 0;
        for ($j = $i; $j < strlen($this->css); $j++) {
            $depth += ($this->css[$j] === '{') - ($this->css[$j] === '}');
            if ($depth === 0) {
                return substr($this->css, $i + 1, $j - $i - 1);
            }
        }

        $this->fail('the wide-screen block is never closed');
    }

    /** Both pieces float, and they float together or the gap looks like a bug. */
    public function test_the_rail_and_the_topbar_are_both_inset(): void
    {
        $wide = $this->wideBlock();

        $this->assertMatchesRegularExpression('/\.lms-sidebar\{[^}]*margin:\.8rem 0 \.8rem \.8rem/s', $wide);
        $this->assertMatchesRegularExpression('/\.lms-topbar\{[^}]*margin:\.8rem \.8rem 0/s', $wide);

        foreach (['.lms-sidebar', '.lms-topbar'] as $part) {
            $this->assertMatchesRegularExpression(
                '/'.preg_quote($part, '/').'\{[^}]*border-radius:1\.1rem/s', $wide,
                "$part is inset but still square, so it reads as a gap rather than a card");
        }
    }

    /**
     * The inset applies ONLY above the drawer breakpoint.
     *
     * This is the assertion that matters. Below 1100px the rail slides over the
     * content, where an inset and a rounded corner mean nothing, and where a
     * stray margin would fight the negative one that hides it.
     */
    public function test_the_float_never_reaches_the_drawer(): void
    {
        $wide = $this->wideBlock();
        $this->assertStringContainsString('.lms-sidebar{', $wide,
            'the rail is inset outside the wide-screen block');

        // The margin must not be set on the rail anywhere a narrow screen sees.
        $outside = str_replace($wide, '', $this->css);
        $this->assertDoesNotMatchRegularExpression(
            '/(?m)^\.lms-sidebar\{[^}]*margin:[^;}]*\d/s', $outside,
            'the rail takes a margin outside the wide-screen block, where it '
            .'would override the negative one that hides the drawer');
    }

    /**
     * The two ways of hiding the rail are still there and still win.
     *
     * `.lms-sidebar.collapsed` outranks the inset on specificity, and the
     * drawer rules sit inside their own narrower media query. Losing either
     * would leave a rail that cannot be put away.
     */
    public function test_the_rail_can_still_be_put_away(): void
    {
        $this->assertMatchesRegularExpression(
            '/\.lms-sidebar\.collapsed\s*\{[^}]*margin-left:calc\(var\(--sidebar-w\)\*-1\)/s', $this->css,
            'the desktop collapse no longer pulls the rail off screen');

        $this->assertMatchesRegularExpression(
            '/\.lms-sidebar\.show-mobile\s*\{[^}]*margin-left:0/s', $this->css,
            'the mobile drawer no longer opens');
    }
}
