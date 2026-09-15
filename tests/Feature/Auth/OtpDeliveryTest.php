<?php

namespace Tests\Feature\Auth;

use App\Mail\OtpCodeMail;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Auth\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * What happens to a code after it is minted, and what the person staring at the
 * OTP screen is told about it.
 */
class OtpDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
    }

    public function test_a_code_that_reaches_the_mailer_is_marked_sent(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        $otp = app(OtpService::class)->issue($user);

        $this->assertSame(OtpCode::DELIVERY_SENT, $otp->delivery_status);
        $this->assertNull($otp->delivery_error);
        Mail::assertQueued(OtpCodeMail::class);
    }

    public function test_a_refused_mail_hand_off_does_not_break_the_login(): void
    {
        $user = User::factory()->create();
        Mail::shouldReceive('to')->once()
            ->andThrow(new \RuntimeException('Connection to smtp.alicia.local:587 refused'));

        // The password step already succeeded; an unreachable relay must not
        // turn that into a stack trace.
        $otp = app(OtpService::class)->issue($user);

        $this->assertSame(OtpCode::DELIVERY_FAILED, $otp->delivery_status);
        $this->assertStringContainsString('refused', $otp->delivery_error);
    }

    public function test_the_otp_screen_says_so_when_delivery_failed(): void
    {
        $user = User::factory()->create();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('Connection refused'));
        app(OtpService::class)->issue($user);

        $response = $this->actingAs($user)->get(route('otp.show'));

        $response->assertOk();
        $response->assertSee('could not be e-mailed', false);
        $response->assertDontSee('We emailed a 6-digit code', false);
    }

    public function test_the_otp_screen_reads_normally_when_the_mail_went_out(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        app(OtpService::class)->issue($user);

        $this->actingAs($user)->get(route('otp.show'))
            ->assertSee('We emailed a 6-digit code', false)
            ->assertDontSee('could not be e-mailed', false);
    }

    public function test_a_bypass_code_behaves_exactly_like_a_mailed_one(): void
    {
        Mail::fake();
        $admin = $this->makeUser('system-admin');
        $user = User::factory()->create();
        $service = app(OtpService::class);

        $code = $service->issueBypass($user, $admin);

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertTrue($service->verify($user, $code), 'A bypass code should verify.');
        $this->assertFalse($service->verify($user, $code), 'A bypass code should verify only once.');
    }

    public function test_a_bypass_code_is_attributed_to_the_administrator_who_issued_it(): void
    {
        Mail::fake();
        $admin = $this->makeUser('system-admin');
        $user = User::factory()->create();

        app(OtpService::class)->issueBypass($user, $admin);
        $otp = OtpCode::where('user_id', $user->id)->latest('id')->firstOrFail();

        $this->assertSame(OtpCode::DELIVERY_BYPASS, $otp->delivery_status);
        $this->assertSame($admin->id, $otp->issued_by_id);
        $this->assertTrue($otp->wasBypass());
        Mail::assertNothingQueued();
    }

    public function test_issuing_any_new_code_retires_the_outstanding_one(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $service = app(OtpService::class);

        $first = $service->issueBypass($user, $this->makeUser('system-admin'));
        $service->issue($user);

        $this->assertFalse($service->verify($user, $first));
    }
}
