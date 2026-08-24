<?php

namespace Tests\Feature\Navigation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sidebar menu scrolls; it does not fold into a second column.
 *
 * Browser zoom shrinks the viewport measured in CSS pixels, so magnifying the
 * page is the same thing to the layout as a short screen. When the menu no
 * longer fitted, it was not scrolling — it was wrapping into a second column
 * that started again at the top of the rail and was then cut off by the rail's
 * `overflow:hidden`. Four Administration items were unreachable at 175% zoom.
 *
 * The cause is a collision the markup makes easy to miss. `.lms-nav` carries
 * Bootstrap's `.nav` (`display:flex; flex-wrap:wrap`) and `.flex-column`, so it
 * is a column box that is allowed to wrap; giving it a definite height so it
 * could scroll is what let the wrap take effect. Because the surplus went
 * sideways there was no vertical overflow, so `overflow-y:auto` never fired.
 *
 * Nothing about this is visible in a passing page render, and any future
 * Bootstrap utility on that element could reintroduce it, so it is asserted.
 */
class SidebarScrollTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
    }

    private function css(): string
    {
        return file_get_contents(public_path('css/app.css'));
    }

    /** @return string the body of the last `.lms-nav { ... }` rule */
    private function navRule(): string
    {
        preg_match_all('/\.lms-nav\s*\{([^}]*)\}/', $this->css(), $matches);
        $this->assertNotEmpty($matches[1], '.lms-nav has no rule at all');

        return implode(';', $matches[1]);
    }

    public function test_the_menu_is_kept_to_a_single_column(): void
    {
        $this->assertMatchesRegularExpression(
            '/flex-wrap\s*:\s*nowrap/',
            $this->navRule(),
            'without flex-wrap:nowrap the menu wraps into a second column instead of scrolling'
        );
    }

    public function test_the_menu_can_shrink_far_enough_to_scroll(): void
    {
        $rule = $this->navRule();

        // A flex item defaults to min-height:auto and refuses to shrink below
        // its content, which clips instead of scrolling.
        $this->assertMatchesRegularExpression('/min-height\s*:\s*0/', $rule);
        $this->assertMatchesRegularExpression('/overflow-y\s*:\s*auto/', $rule);
    }

    /**
     * The rail is the scroll boundary: it holds the viewport height and hides
     * its own overflow, so only the nav inside it moves.
     */
    public function test_the_rail_holds_its_height_and_lets_the_nav_do_the_scrolling(): void
    {
        preg_match_all('/\.lms-sidebar\s*\{([^}]*)\}/', $this->css(), $matches);
        $rail = implode(';', $matches[1]);

        $this->assertMatchesRegularExpression('/height\s*:\s*100dvh/', $rail);
        $this->assertMatchesRegularExpression('/overflow\s*:\s*hidden/', $rail);
        $this->assertMatchesRegularExpression('/flex-direction\s*:\s*column/', $rail);
    }

    /**
     * The wrap only bites because the element carries Bootstrap's `.nav`. If
     * that class is ever dropped the override above becomes dead weight, and if
     * it is kept the override has to stay — either way the two travel together.
     */
    public function test_the_markup_still_carries_the_class_the_override_answers(): void
    {
        $blade = file_get_contents(resource_path('views/partials/sidebar.blade.php'));

        $this->assertStringContainsString('lms-nav nav flex-column', $blade,
            'the flex-wrap:nowrap override in app.css exists to answer this class list');
    }

    /**
     * The content column sits against the rail rather than being centred in
     * what is left of the screen.
     *
     * `max-width:1440px` with `margin:0 auto` split the surplus into two equal
     * gutters, and the left one landed beside the sidebar — 108px on a 1920
     * screen and 428px at 75% zoom, because zooming out widens the viewport in
     * CSS pixels rather than narrowing it.
     */
    public function test_the_content_is_not_centred_into_a_gutter_beside_the_rail(): void
    {
        // The stylesheet is written in layers, so the earlier declarations are
        // still in the file and the last one is what the browser uses. Assert
        // the effective value rather than the presence of any one line.
        $css = preg_replace('/@media\s+print\s*\{.*?\}\s*\}/s', '', $this->css());
        preg_match_all('/\.lms-content\s*\{([^}]*)\}/', $css, $matches);
        $declarations = implode(';', $matches[1]);

        preg_match_all('/max-width\s*:\s*([^;}]+)/', $declarations, $widths);
        $this->assertSame('none', trim(end($widths[1])),
            'a max-width on the content column reappears as a gutter against the sidebar');

        preg_match_all('/(?<!-)margin\s*:\s*([^;}]+)/', $declarations, $margins);
        $this->assertDoesNotMatchRegularExpression('/\bauto\b/', trim(end($margins[1])),
            'an auto side margin is what turns leftover width into that gutter');
    }

    /**
     * Prose is the one thing a wide screen can be too wide for, so the cap that
     * came off the app belongs on the page that is actually running text.
     */
    public function test_the_instructions_page_keeps_a_readable_measure(): void
    {
        $this->assertMatchesRegularExpression(
            '/\.instr-page\s+\.csc-instr-cols\s*\{[^}]*max-width\s*:/',
            $this->css(),
            'without a cap each column of the instructions reaches about 150 characters'
        );
    }

    /** The whole menu reaches the page, whatever height the browser has. */
    public function test_every_permitted_item_is_rendered_into_the_rail(): void
    {
        $this->actingAs($this->makeUser('system-admin'));
        session(['otp_verified' => true]);

        $html = $this->get('/dashboard')->assertOk()->getContent();

        foreach (['Dashboard', 'Users', 'Roles &amp; Permissions', 'Authorized Devices',
            'Security Dashboard', 'Blocked IPs', 'Intrusion Logs', 'Audit Logs',
            'Activity Logs', 'System Settings'] as $label) {
            $this->assertStringContainsString('<span>'.$label.'</span>', $html, $label);
        }
    }
}
