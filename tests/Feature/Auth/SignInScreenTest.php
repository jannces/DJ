<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sign-in screen.
 *
 * It carries two photographs of the municipality, referenced from the
 * stylesheet rather than a template, so nothing fails loudly if one goes
 * missing — the panel just renders empty and nobody notices until it is on a
 * projector. It also carries two decisions worth holding: the failure message
 * has to be somewhere a person will read it, and there is no way to skip the
 * one-time code.
 */
class SignInScreenTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string,int> file => smallest size that is plausibly the real image */
    private const ASSETS = [
        'img/alicia-hall-zoom.jpg' => 100_000,
        'img/alicia-seal.png' => 40_000,
        'img/one-alicia.png' => 20_000,
    ];

    private function css(): string
    {
        return file_get_contents(public_path('css/app.css'));
    }

    // ------------------------------------------------------------- the images

    public function test_every_photograph_is_present_and_is_not_a_placeholder(): void
    {
        foreach (self::ASSETS as $path => $floor) {
            $this->assertFileExists(public_path($path));
            $this->assertGreaterThan($floor, filesize(public_path($path)), "{$path} is too small");
        }
    }

    public function test_nothing_is_asked_for_that_is_not_there(): void
    {
        preg_match_all("#url\(['\"]?(\.\./img/[^'\")]+)#", $this->css(), $m);
        $this->assertNotEmpty($m[1], 'the sign-in screen references no images at all');

        foreach ($m[1] as $reference) {
            $this->assertFileExists(public_path('css/'.$reference),
                "the stylesheet asks for {$reference} and it is not there");
        }
    }

    // -------------------------------------------------------------- the scrim

    /**
     * `rgb(R,G,B / A)` is invalid — slash alpha needs space-separated channels,
     * and the whole declaration is dropped when it is wrong. That failed
     * silently once: the scrim vanished and the photograph rendered raw with
     * white text on it.
     */
    public function test_the_scrim_colours_are_in_the_syntax_the_slash_alpha_needs(): void
    {
        preg_match_all('/--g[123]:\s*([^;]+);/', $this->css(), $m);
        $this->assertNotEmpty($m[1], 'the scrim defines no colours');

        foreach ($m[1] as $triplet) {
            $this->assertStringNotContainsString(',', $triplet,
                "--g holds '{$triplet}'; a comma here makes every rgb(... / alpha) using it invalid");
            $this->assertMatchesRegularExpression('/^\d{1,3}\s+\d{1,3}\s+\d{1,3}$/', trim($triplet));
        }
    }

    public function test_the_hall_sits_under_a_scrim_with_actual_range(): void
    {
        preg_match('/\.auth-aside::before\{([^}]*)\}/s', $this->css(), $m);
        $this->assertNotEmpty($m, '.auth-aside::before is gone — there is no scrim');

        preg_match_all('/calc\(var\(--op\)\*\.(\d+)\)|var\(--op\)\)/', $m[1], $stops);
        $this->assertGreaterThanOrEqual(5, count($stops[0]),
            'a two-stop ramp reads as a tint, not a gradient');

        $factors = array_map(static fn ($f) => $f === '' ? 100 : (int) str_pad($f, 2, '0'), $stops[1]);
        $this->assertLessThanOrEqual(60, min($factors),
            'nothing opens far enough for the building to show through');
    }

    /** Multiply against a dark ground erases the mark, so it needs a light panel. */
    public function test_the_mark_is_blended_rather_than_pasted_on(): void
    {
        preg_match('/\.auth-main::before\{([^}]*)\}/s', $this->css(), $m);
        $this->assertNotEmpty($m);

        $rule = str_replace(' ', '', $m[1]);
        $this->assertStringContainsString('one-alicia', $rule);
        $this->assertStringContainsString('mix-blend-mode:multiply', $rule);
        $this->assertStringContainsString('pointer-events:none', $rule,
            'the layer covers the panel and must not swallow clicks on the form');

        $this->assertMatchesRegularExpression('/\.auth-main\{[^}]*isolation:isolate/s', $this->css(),
            'without isolation the blend reaches past this panel');
    }

    // --------------------------------------------------------------- the card

    /**
     * The controller already reports how many attempts remain, when a block
     * lifts and how long a throttle has left. Rendered under a field at 12px
     * that is information nobody reads.
     */
    public function test_a_failure_is_reported_where_somebody_will_read_it(): void
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $html = $this->from('/login')
            ->followingRedirects()
            ->post('/login', ['identifier' => $user->username, 'password' => 'not-the-password'])
            ->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/class="auth-note auth-note-bad"[^>]*role="alert"/', $html,
            'the failure has no alert region at the top of the card');
        $this->assertStringContainsString('attempt(s) remaining', $html,
            'the remaining-attempt count the controller computed never reaches the page');
        $this->assertStringContainsString('aria-describedby="auth-alert"', $html,
            'the field has to point at the region, since the message is no longer printed twice');
        $this->assertSame(1, substr_count($html, 'attempt(s) remaining'),
            'the same message must not appear both in the region and under the field');
    }

    public function test_the_password_reveal_is_reachable_and_named(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<button[^>]*class="auth-eye"[^>]*aria-label="Show password"/', $html);
        $this->assertDoesNotMatchRegularExpression('/class="auth-eye"[^>]*tabindex="-1"/', $html,
            'a negative tabindex puts the control out of reach of the keyboard');
    }

    /**
     * A remember-me cookie walks straight past the one-time code. On a counter
     * machine shared across the municipal hall that is how one person inherits
     * another's session.
     */
    public function test_there_is_no_way_to_stay_signed_in_past_the_one_time_code(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringNotContainsString('name="remember"', $html);
        $this->assertStringNotContainsStringIgnoringCase('Keep me signed in', $html);
    }

    public function test_the_footer_names_the_office_that_actually_creates_accounts(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        // The controller's own blocked-account message says the same thing;
        // pointing at HR here would contradict it on the same screen.
        $this->assertStringContainsString('System Administrator', $html);
        $this->assertStringNotContainsString('HR Management Office', $html);
    }

    public function test_the_screen_still_renders_with_both_halves(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('auth-aside', $html);
        $this->assertStringContainsString('auth-main', $html);
        $this->assertStringContainsString('alicia-seal.png', $html);
        $this->assertStringContainsString('Local Government', $html);
        $this->assertStringContainsString('name="identifier"', $html);
        $this->assertStringContainsString('name="password"', $html);
    }

    /** Signing in is unchanged — this was a change of shell, not of behaviour. */
    public function test_a_correct_password_still_signs_in(): void
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        // The factory's shared password; the point is that the new shell did
        // not disturb the flow, not what the string is.
        $this->post('/login', [
            'identifier' => $user->username,
            'password' => 'Secret!Passw0rd#1',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_the_photograph_is_not_sent_to_phones(): void
    {
        $this->assertMatchesRegularExpression(
            '/@media\(max-width:900px\)\{.*?\.auth-aside\{display:none/',
            preg_replace('/\s+/', '', $this->css()),
            'the aside must stay display:none on small screens'
        );
    }
}
