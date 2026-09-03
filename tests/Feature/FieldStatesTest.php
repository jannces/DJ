<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Six field states, one system.
 *
 * A text field has six: default, focus, error, success, disabled and loading.
 * Each needs an explicit design, because a state nobody designed is one the
 * browser invents -- and the browser's idea of "invalid" is a red border,
 * which roughly one man in twelve cannot see.
 *
 * These assertions are on the stylesheet rather than on a rendered page, and
 * deliberately so: five of the six states cannot be produced by a server
 * response at all. They exist as CSS or they do not exist. What a request test
 * CAN prove -- that a label is a real label and not a placeholder -- is at the
 * bottom of this file, and that one scans every view in the application.
 */
class FieldStatesTest extends TestCase
{
    private string $css;

    private string $js;

    protected function setUp(): void
    {
        parent::setUp();
        $this->css = file_get_contents(public_path('css/app.css'));
        $this->js = file_get_contents(public_path('js/app.js'));
    }

    /**
     * One definition, not three.
     *
     * This file used to carry three separate `.form-control,.form-select`
     * blocks in three different sections, disagreeing on border, radius and
     * font size. The last one won, and it set `box-shadow:none` on :focus --
     * which silently deleted the focus ring from every input in the system.
     * Nobody wrote that rule to remove the ring; it removed it by arriving
     * last. One block is the fix.
     */
    public function test_the_base_field_is_defined_exactly_once(): void
    {
        $this->assertSame(1, preg_match_all('/^\.form-control,\.form-select\{/m', $this->css),
            'more than one base definition: whichever lands last silently wins');
    }

    // ------------------------------------------------------------ 1. default

    public function test_the_label_sits_outside_the_field_and_the_helper_below(): void
    {
        $this->assertMatchesRegularExpression('/\.form-label\{[^}]*display:block/s', $this->css);
        $this->assertMatchesRegularExpression('/\.form-text\{[^}]*display:block/s', $this->css);
    }

    /**
     * The field boundary is visible.
     *
     * --border-strong measures 1.4:1 on white -- a hairline, not an edge. WCAG
     * 1.4.11 asks for 3:1 on whatever tells you where a control is, and on a
     * white field on a near-white page the border is the only thing that does.
     */
    public function test_the_resting_border_clears_three_to_one(): void
    {
        $this->assertMatchesRegularExpression(
            '/\.form-control,\.form-select\{[^}]*border:1px solid var\(--field-border\)/s', $this->css);

        foreach (['#8f86ab' => '#ffffff', '#6d6094' => '#1a1330'] as $border => $surface) {
            $this->assertStringContainsString($border, $this->css, "the $border token is gone");
            $this->assertGreaterThanOrEqual(3.0, $this->contrast($border, $surface),
                "the field border is under 3:1 on $surface");
        }
    }

    // -------------------------------------------------------------- 2. focus

    /** A ring, and one that can actually be seen against the field. */
    public function test_focus_draws_a_ring_of_at_least_three_to_one(): void
    {
        $this->assertMatchesRegularExpression(
            '/\.form-control:focus,\.form-select:focus\{(?:(?!\}).)*box-shadow:0 0 0 3px/s', $this->css,
            'the focus ring is missing');
        $this->assertDoesNotMatchRegularExpression(
            '/\.form-control:focus,\.form-select:focus\{(?:(?!\}).)*box-shadow:none/s', $this->css,
            'a rule cancels the focus ring');

        // --primary against each theme's field fill.
        $this->assertGreaterThanOrEqual(3.0, $this->contrast('#3f2f83', '#ffffff'));
        $this->assertGreaterThanOrEqual(3.0, $this->contrast('#7a68c4', '#1a1330'));
    }

    // -------------------------------------------------------------- 3. error

    /**
     * Colour AND icon AND words -- all three, never one.
     *
     * Colour alone fails colour-vision deficiency. An icon alone does not say
     * what is wrong. Words alone are missed by somebody scanning a long form
     * for the field that stopped them.
     */
    public function test_an_error_is_colour_and_icon_and_message(): void
    {
        // colour
        $this->assertMatchesRegularExpression(
            '/\.form-control\.is-invalid[^{]*\{[^}]*border-color:var\(--danger\)/s', $this->css);
        // icon, inside the field
        $this->assertMatchesRegularExpression(
            '/\.form-control\.is-invalid[^{]*\{[^}]*background-image:var\(--icon-error\)/s', $this->css);
        // icon again on the message, so the line is findable without reading it
        $this->assertMatchesRegularExpression(
            '/\.invalid-feedback[^{]*::before\{[^}]*var\(--icon-error\)/s', $this->css);
        // and the message is shown at all
        $this->assertStringContainsString('.invalid-feedback{ display:flex !important; }', $this->css);
    }

    /** Both themes carry an error icon, since one hard-coded hex cannot serve both. */
    public function test_the_error_icon_is_defined_for_both_themes(): void
    {
        $this->assertSame(2, substr_count($this->css, '--icon-error:url('),
            'one theme has no error icon');
        $this->assertSame(2, substr_count($this->css, '--icon-ok:url('));
    }

    /** No error state anywhere relies on a border alone. */
    public function test_no_error_state_is_border_only(): void
    {
        // The sign-in field and the checkbox group both used to be exactly
        // that, on the two screens where being unable to tell what went wrong
        // leaves a person stuck.
        $this->assertMatchesRegularExpression('/\.auth-input\.is-bad::after\{[^}]*background:url/s', $this->css);
        $this->assertMatchesRegularExpression('/\.is-invalid-group::before\{[^}]*var\(--icon-error\)/s', $this->css);
    }

    // ------------------------------------------------------------ 4. success

    public function test_success_is_confirmed_inside_the_field(): void
    {
        $this->assertMatchesRegularExpression(
            '/\.form-control\.is-valid[^{]*\{[^}]*background-image:var\(--icon-ok\)/s', $this->css);
        $this->assertMatchesRegularExpression('/\.valid-feedback[^{]*\{[^}]*color:var\(--success\)/s', $this->css);
    }

    // ----------------------------------------------------------- 5. disabled

    /**
     * A grey fill and a cursor that says no -- never opacity.
     *
     * A control at opacity .5 is how every other interface on the machine says
     * "working on it". Faking disabled that way makes it unreadable AND makes
     * it lie about which state it is in.
     */
    public function test_disabled_is_a_fill_and_a_cursor_not_a_fade(): void
    {
        $this->assertMatchesRegularExpression(
            '/\.form-control:disabled[^{]*\{(?:(?!\}).)*cursor:not-allowed/s', $this->css);
        $this->assertMatchesRegularExpression(
            '/\.form-control:disabled[^{]*\{(?:(?!\}).)*opacity:1/s', $this->css);
        $this->assertMatchesRegularExpression(
            '/\.btn:disabled[^{]*\{(?:(?!\}).)*opacity:1/s', $this->css,
            'a disabled button still fades instead of greying');
    }

    // ------------------------------------------------------------ 6. loading

    /**
     * Loading exists, blocks input, and does not look like disabled.
     *
     * The reason it exists is the double submit: a second click on Submit
     * files a duplicate leave application with its own reference number, which
     * somebody then cancels by hand.
     */
    public function test_loading_blocks_input_and_is_distinct_from_disabled(): void
    {
        $this->assertMatchesRegularExpression(
            '/form\[aria-busy="true"\] \.form-control,(?:(?!\}).)*pointer-events:none/s', $this->css);
        $this->assertMatchesRegularExpression(
            '/form\[aria-busy="true"\] \.form-control,(?:(?!\}).)*animation:field-sweep/s', $this->css,
            'loading is a flat fill, which is what disabled already looks like');
        $this->assertMatchesRegularExpression(
            '/form\[aria-busy="true"\] button\[type="submit"\]::after\{[^}]*animation:field-spin/s', $this->css);
        $this->assertStringContainsString('@media (prefers-reduced-motion:reduce)', $this->css);
    }

    /** And something actually turns it on -- and off again. */
    public function test_the_loading_state_is_wired_to_form_submission(): void
    {
        $this->assertStringContainsString("form.setAttribute('aria-busy', 'true')", $this->js);

        // A form that another listener has already handled is not navigating,
        // so locking it would freeze a page that is staying put.
        $this->assertStringContainsString('if (e.defaultPrevented) return;', $this->js);

        // Coming back with the Back button restores the page mid-submit, with
        // every field grey and the button spinning for a request that finished
        // long ago.
        $this->assertMatchesRegularExpression(
            "/pageshow.*?removeAttribute\('aria-busy'\)/s", $this->js,
            'the Back button leaves the form locked for ever');
    }

    // ------------------------------------------- the one that scans the views

    /**
     * No input is labelled only by its placeholder.
     *
     * A placeholder is an example of the answer. Used as the question it
     * disappears the moment somebody types, leaving a filled box with nothing
     * on screen saying what is in it -- and anyone who looks away mid-form has
     * to clear the field to find out.
     */
    public function test_no_field_uses_its_placeholder_as_its_label(): void
    {
        $offenders = [];

        foreach ($this->views() as $file) {
            $html = file_get_contents($file);

            preg_match_all('/<(?:input|select|textarea)\b[^>]*placeholder="[^"]+"[^>]*>/', $html, $m);

            foreach ($m[0] as $control) {
                if (str_contains($control, 'aria-label')) {
                    continue;
                }
                if (preg_match('/\bid="([^"]+)"/', $control, $id)
                    && str_contains($html, 'for="'.$id[1].'"')) {
                    continue;
                }
                $offenders[] = basename($file).': '.substr($control, 0, 80);
            }
        }

        $this->assertSame([], $offenders,
            "a placeholder is standing in for a label:\n".implode("\n", $offenders));
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

    /** WCAG relative-luminance contrast between two hex colours. */
    private function contrast(string $a, string $b): float
    {
        $l = fn (string $hex): float => (function (array $c): float {
            $f = fn (float $v): float => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;

            return 0.2126 * $f($c[0]) + 0.7152 * $f($c[1]) + 0.0722 * $f($c[2]);
        })(array_map(
            fn ($p) => hexdec($p) / 255,
            str_split(ltrim($hex, '#'), 2)
        ));

        $la = $l($a);
        $lb = $l($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }
}
