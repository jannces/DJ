<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * The code entry on the OTP screen.
 *
 * Six boxes are a view of one string. Building them as six inputs means
 * re-implementing paste, auto-advance, backspace-on-empty and autofill by hand
 * — every one a place to get it wrong, and none of it working with JavaScript
 * off. The single input is the whole reason those behaviours are free, so it
 * is the thing worth guarding.
 */
class OtpScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
    }

    private function signIn(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        session(['otp_verified' => false]);

        return $user;
    }

    private function css(): string
    {
        return file_get_contents(public_path('css/app.css'));
    }

    // ------------------------------------------------------------- the field

    public function test_the_code_is_one_field_and_the_cells_are_a_view_of_it(): void
    {
        $this->signIn();
        $html = $this->get('/otp')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'name="code"'),
            'six inputs means hand-writing paste, focus and backspace; there must be one');
        $this->assertSame(6, substr_count($html, '<span></span>'),
            'the six cells are missing');
        $this->assertMatchesRegularExpression('/<div class="otp-cells" aria-hidden="true">/', $html,
            'the cells are decoration and must not be announced as content');
    }

    public function test_the_field_asks_the_browser_for_everything_it_can_give(): void
    {
        $this->signIn();
        $html = $this->get('/otp')->assertOk()->getContent();

        foreach ([
            'autocomplete="one-time-code"',   // the emailed code offered as autofill
            'inputmode="numeric"',            // the numeric keypad on a phone
            'maxlength="6"',
            'autofocus',                      // also the refocus after a wrong code
        ] as $attribute) {
            $this->assertStringContainsString($attribute, $html, $attribute.' is missing');
        }
    }

    /**
     * A character advances by `1ch + letter-spacing`; a cell repeats every
     * `cell + gap`. Equal, and every digit lands centred in its own box. If one
     * side is edited without the other the digits walk out of the cells.
     */
    public function test_the_cells_and_the_characters_share_one_measurement(): void
    {
        $css = preg_replace('/\s+/', '', $this->css());

        $this->assertStringContainsString('letter-spacing:calc(var(--cw)+var(--cg)-1ch)', $css,
            'the character advance no longer derives from the cell pitch');
        $this->assertStringContainsString('padding:000calc((var(--cw)-1ch)/2)', $css,
            'the first digit is no longer centred in the first cell');
        $this->assertStringContainsString('width:calc(var(--cw)*6+var(--cg)*5)', $css,
            'the track width no longer matches six cells and five gaps');
    }

    // ----------------------------------------------------------- the message

    public function test_a_wrong_code_shakes_the_cells_without_any_script(): void
    {
        $this->signIn();

        $html = $this->from('/otp')->followingRedirects()
            ->post('/otp/verify', ['code' => '000000'])
            ->assertOk()->getContent();

        $this->assertStringContainsString('class="otp is-bad"', $html);
        $this->assertMatchesRegularExpression('/\.otp\.is-bad\{[^}]*animation:otp-shake/',
            preg_replace('/\s+/', '', $this->css()),
            'the shake has to be a CSS animation on load, since verification is a page render');
        $this->assertStringContainsString('autofocus', $html, 'the cleared field is not refocused');
    }

    /**
     * A failed verification can still carry the status flashed by the resend
     * before it. Showing both would tell somebody a new code had arrived when
     * none had.
     */
    public function test_only_one_message_shows_and_the_error_wins(): void
    {
        $this->signIn();

        $html = $this->from('/otp')->followingRedirects()
            ->withSession(['status' => 'A new code is on its way to your inbox.'])
            ->post('/otp/verify', ['code' => '000000'])
            ->assertOk()->getContent();

        $this->assertStringContainsString('auth-note-bad', $html);
        $this->assertStringNotContainsString('auth-note-ok', $html,
            'the success flash must be suppressed while an error is showing');
        $this->assertSame(1, substr_count($html, 'class="auth-note'),
            'more than one message is on screen');
    }

    /** The layout renders the flash unless the view says it will place it. */
    public function test_the_flash_is_not_rendered_twice(): void
    {
        $this->signIn();

        $html = $this->withSession(['status' => 'We emailed you a one-time password.'])
            ->get('/otp')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'We emailed you a one-time password.'),
            'the layout and the view are both drawing the flash');
        $this->assertSame(1, substr_count($html, 'auth-note-ok'));
    }

    // ------------------------------------------------------------ the resend

    /** @return string the resend button, markup and all */
    private function resendButton(string $html): string
    {
        preg_match('#<button[^>]*id="otp-resend".*?</button>#s', $html, $m);
        $this->assertNotEmpty($m, 'there is no resend button');

        return $m[0];
    }

    public function test_resend_is_offered_when_the_server_would_accept_it(): void
    {
        $this->signIn();
        $button = $this->resendButton($this->get('/otp')->assertOk()->getContent());

        $this->assertStringContainsString('Resend code', $button);
        $this->assertStringNotContainsString('Resend in', $button);
        $this->assertStringNotContainsString('disabled', $button);
    }

    /**
     * The countdown is not decoration. The controller refuses a fourth resend
     * inside two minutes, and the button is disabled for exactly as long as
     * that refusal lasts rather than inviting a press that will be rejected.
     */
    public function test_resend_is_disabled_for_as_long_as_the_server_will_refuse_it(): void
    {
        $user = $this->signIn();

        $key = 'otp-resend|'.$user->id;
        for ($i = 0; $i < 3; $i++) {
            RateLimiter::hit($key, 120);
        }

        $button = $this->resendButton($this->get('/otp')->assertOk()->getContent());

        $this->assertStringContainsString('Resend in', $button);
        $this->assertStringContainsString('disabled', $button);
        $this->assertMatchesRegularExpression('/data-in="[1-9]\d*"/', $button,
            'the button needs the real remaining seconds to count down from');
    }

    // ------------------------------------------------------------- the shell

    public function test_it_stays_in_the_same_container_as_the_sign_in_screen(): void
    {
        $this->signIn();
        $html = $this->get('/otp')->assertOk()->getContent();

        $this->assertStringContainsString('auth-aside', $html);
        $this->assertStringContainsString('auth-main', $html);
        $this->assertStringContainsString('alicia-seal.png', $html);
        $this->assertStringContainsString('Local Government', $html);
    }

    /** Verification itself is untouched — this was a change of screen only. */
    public function test_a_correct_code_still_verifies(): void
    {
        $user = $this->signIn();

        \App\Models\OtpCode::create([
            'user_id' => $user->id,
            'code_hash' => hash('sha256', '123456'),
            'purpose' => 'login',
            'expires_at' => now()->addMinutes(5),
        ]);

        $this->post('/otp/verify', ['code' => '123456'])->assertRedirect();
        $this->assertTrue(session('otp_verified'));
    }
}
