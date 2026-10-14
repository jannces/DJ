<?php

namespace Tests\Feature\Navigation;

use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Three faults in how buttons looked, all of them invisible to a test that only
 * asked whether the markup was present.
 */
class ButtonAppearanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
    }

    private function css(): string
    {
        return preg_replace('/\s+/', '', file_get_contents(public_path('css/app.css')));
    }

    private function signIn(string $role = 'system-admin'): void
    {
        $this->actingAs($this->makeUser($role));
        session(['otp_verified' => true]);
    }

    /**
     * `{{ }}` escapes, so a label written as `&amp;` in Blade is escaped a
     * second time and reaches the page as the literal text "&amp;". The back
     * link read "Roles &amp; Permissions" on screen.
     */
    public function test_no_back_label_is_escaped_twice(): void
    {
        $this->signIn();
        $role = Role::where('slug', 'hr')->firstOrFail();

        $html = $this->get('/roles/'.$role->id.'/edit')->assertOk()->getContent();

        preg_match('#<a[^>]*class="back-link".*?</a>#s', $html, $link);
        $this->assertNotEmpty($link, 'there is no back link');

        $this->assertStringNotContainsString('&amp;amp;', $link[0],
            'the label was escaped twice and renders as the literal "&amp;"');
        $this->assertStringContainsString('Roles &amp; Permissions', $link[0]);
    }

    /** Nothing anywhere may hand Blade a pre-escaped entity to escape again. */
    public function test_no_view_passes_a_pre_escaped_label(): void
    {
        $offenders = [];

        foreach ($this->bladeFiles() as $file) {
            if (preg_match('/back-label="[^"]*&amp;/', file_get_contents($file))) {
                $offenders[] = basename(dirname($file)).'/'.basename($file);
            }
        }

        $this->assertSame([], $offenders,
            'these pass an HTML entity to a label that is escaped again on the way out');
    }

    /**
     * It had a transparent border and no background, so it only became a
     * control once the pointer was on it — no use to somebody looking for the
     * way back, and none at all on a touch screen, where there is no hover to
     * discover it with.
     */
    public function test_the_back_link_looks_like_a_button_before_it_is_hovered(): void
    {
        $css = $this->css();

        preg_match('/\.back-link\{([^}]*)\}/', $css, $rule);
        $this->assertNotEmpty($rule, '.back-link has no rule at all');

        $this->assertStringNotContainsString('border:1pxsolidtransparent', $rule[1],
            'the border is invisible until hover, so the control is too');
        $this->assertMatchesRegularExpression('/background-color:var\(--surface\)/', $rule[1],
            'a button needs a surface of its own, not the page behind it');
        $this->assertStringContainsString('box-shadow:', $rule[1]);
        $this->assertMatchesRegularExpression('/border:1pxsolidvar\(--border-strong\)/', $rule[1]);

        // Hover changes it; hover does not create it.
        $this->assertMatchesRegularExpression('/\.back-link:hover[^{]*\{[^}]*color:var\(--primary\)/', $css);
    }

    /**
     * `text-align:center` stops applying the moment an element becomes a flex
     * container, so a button that went flex to line up an icon packed its
     * label to the start — visibly off-centre on anything full-width.
     */
    public function test_every_flex_button_centres_its_own_label(): void
    {
        $css = preg_replace('#/\*.*?\*/#s', '', file_get_contents(public_path('css/app.css')));
        $flat = preg_replace('/\s+/', '', $css);

        foreach (['.back-link', '.dash-link', '.report-acts.btn-view', '.report-acts.btn-fmt'] as $selector) {
            $this->assertStringContainsString(
                $selector.'{', str_replace(' ', '', $flat),
                $selector.' has no rule'
            );
        }

        // The blanket guarantees, whichever shape a button ends up being.
        $this->assertStringContainsString('.btn{text-align:center;}', $flat);
        $this->assertMatchesRegularExpression('/\.btn\.w-100[^{]*\{[^}]*justify-content:center/', $flat);
        $this->assertMatchesRegularExpression('/\.back-link[,{][^}]*justify-content:center/', $flat);

        // ...and a caption is not a button, so it keeps its own alignment.
        $this->assertMatchesRegularExpression('/span\.dash-link\{[^}]*justify-content:flex-start/', $flat);
    }

    /** The one full-width button on the page the report came from. */
    public function test_the_save_button_is_full_width_and_centred(): void
    {
        $this->signIn();
        $role = Role::where('slug', 'hr')->firstOrFail();

        $html = $this->get('/roles/'.$role->id.'/edit')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/class="btn btn-lgu w-100"[^>]*>\s*Save role/', $html);
        $this->assertMatchesRegularExpression('/\.btn\.w-100[^{]*\{[^}]*justify-content:center/',
            $this->css());
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
