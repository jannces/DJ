<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The system wears the municipality's own mark.
 *
 * Both places it appears were placeholders of the kind every admin template
 * ships with: a `bi-buildings` glyph in a coloured square at the top of the
 * navigation, and a scales emoji drawn into an inline SVG as the favicon.
 * Neither said which municipality this belongs to.
 *
 * Three assets, each cut to its slot. The sidebar takes the FIST ALONE,
 * because One Alicia's full lockup carries its own wordmark and at 34px that
 * wordmark is a smudge sitting beside a perfectly readable "LGU Alicia" in
 * type. The favicon takes the circular seal, which is the only one of the
 * three that survives 16px -- and is what the sign-in page already used, so
 * the two layouts now agree.
 */
class BrandMarkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();

        $this->actingAs($this->makeUser('hr'));
        session(['otp_verified' => true]);
    }

    public function test_the_navigation_carries_the_one_alicia_mark(): void
    {
        $html = $this->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('class="brand-mark"', $html);
        $this->assertStringContainsString('img/one-alicia-mark.png', $html);

        // The glyph-in-a-square it replaced.
        $this->assertStringNotContainsString('<div class="seal"><i class="bi bi-buildings">', $html,
            'the placeholder mark is back in the sidebar');
    }

    /** The name stays in type beside the mark, both lines of it. */
    public function test_the_name_still_sits_beside_the_mark(): void
    {
        $html = $this->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('<div class="brand-name">LGU Alicia</div>', $html);
        $this->assertStringContainsString('class="brand-sub">Leave Management', $html);
    }

    /**
     * The mark is decorative, and says so.
     *
     * "LGU Alicia" is right beside it in text. An alt of "One Alicia" would
     * have a screen reader announce the organisation twice in a row.
     */
    public function test_the_mark_does_not_repeat_the_words_beside_it(): void
    {
        $this->assertMatchesRegularExpression(
            '/<img class="brand-mark"[^>]*alt=""[^>]*aria-hidden="true"/',
            $this->get('/dashboard')->assertOk()->getContent());
    }

    /**
     * Width and height are on the element, so the sidebar does not jump.
     *
     * Without them the brand row has no height until the file arrives and
     * every nav item below it shifts down on load.
     */
    public function test_the_mark_reserves_its_own_space(): void
    {
        $this->assertMatchesRegularExpression(
            '/<img class="brand-mark"[^>]*width="256"[^>]*height="256"/',
            $this->get('/dashboard')->assertOk()->getContent());
    }

    /**
     * The short-viewport rule shrinks the mark, not the element it replaced.
     *
     * That block compressed `.seal`, which is gone from the brand row; without
     * this the row keeps full height on a landscape phone and eats the rail.
     */
    public function test_the_short_viewport_rule_was_moved_onto_the_mark(): void
    {
        $css = file_get_contents(public_path('css/app.css'));
        $block = substr($css, strpos($css, '@media (max-height:520px)'), 400);

        $this->assertStringContainsString('.lms-brand .brand-mark{ width:24px; height:24px; }', $block,
            'the compressed brand row still only shrinks the old glyph square');
    }

    /** The favicon is the seal, and it is the same one the sign-in page uses. */
    public function test_both_layouts_show_the_same_seal_in_the_tab(): void
    {
        $app = $this->get('/dashboard')->assertOk()->getContent();
        $this->assertStringContainsString('<link rel="icon" href="'.asset('img/alicia-seal.png').'">', $app);
        $this->assertStringNotContainsString('data:image/svg+xml', $app,
            'the placeholder favicon is still being drawn inline');

        auth()->logout();
        $this->assertStringContainsString('img/alicia-seal.png',
            $this->get('/login')->assertOk()->getContent());
    }
}
