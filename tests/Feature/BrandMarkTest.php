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
 * One asset in all three places: the municipal seal. It is already round and
 * still legible small -- its ring, hills and rice sheaf survive at 34px in the
 * rail and at 16px in the tab, where a wordmark would be a smudge. The
 * sign-in page already used it, so the navigation, the tab and the sign-in
 * screen finally show the same thing.
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

    public function test_the_navigation_carries_the_municipal_seal(): void
    {
        $html = $this->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('class="brand-mark"', $html);
        $this->assertStringContainsString('img/alicia-seal.png', $html);

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
            '/<img class="brand-mark"[^>]*width="400"[^>]*height="400"/',
            $this->get('/dashboard')->assertOk()->getContent());
    }

    /**
     * The mark is a disc, and the artwork is inset rather than cropped.
     *
     * A circle cut straight out of the square would slice the yellow field at
     * four points and read as damage. object-fit:contain scales the whole
     * artwork into the disc instead, so the circle is made of white space.
     */
    public function test_the_mark_is_a_disc_that_does_not_cut_the_artwork(): void
    {
        $css = file_get_contents(public_path('css/app.css'));
        preg_match('/\.lms-brand \.brand-mark\{(.*?)\}/s', $css, $m);

        $this->assertNotEmpty($m, 'the brand mark has no rule of its own');
        $this->assertStringContainsString('border-radius:50%', $m[1]);
        $this->assertStringContainsString('object-fit:contain', $m[1],
            'the artwork is being cropped to the circle rather than fitted inside it');
        $this->assertStringNotContainsString('object-fit:cover', $m[1]);

        // The seal draws its own ring. A disc behind it would be a second one.
        $this->assertStringNotContainsString('background:#fff', $m[1],
            'a white disc sits behind a mark that is already round');
        $this->assertStringNotContainsString('border:1px', $m[1]);
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
