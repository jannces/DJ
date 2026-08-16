<?php

namespace Tests\Feature\Auth;

use App\Mail\OtpCodeMail;
use App\Models\User;
use App\Services\Auth\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OtpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
    }

    public function test_correct_otp_unlocks_the_session(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // Issue and capture a known code by seeding one via the service double.
        $service = app(OtpService::class);
        $service->issue($user);
        // Re-issue with a deterministic code by inserting directly.
        \App\Models\OtpCode::where('user_id', $user->id)->update(['consumed_at' => now()]);
        \App\Models\OtpCode::create([
            'user_id' => $user->id,
            'code_hash' => hash('sha256', '123456'),
            'purpose' => 'login',
            'expires_at' => now()->addMinutes(5),
        ]);

        $this->post('/otp/verify', ['code' => '123456'])->assertRedirect();
        $this->assertTrue(session('otp_verified'));
    }

    public function test_code_is_emailed_to_the_account_address(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'juan.delacruz@alicia.gov.ph']);

        $this->assertTrue(app(OtpService::class)->issue($user));

        Mail::assertSent(OtpCodeMail::class, fn (OtpCodeMail $mail) => $mail->hasTo('juan.delacruz@alicia.gov.ph'));
    }

    public function test_delivery_failure_is_reported_to_the_caller(): void
    {
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('smtp unreachable'));
        $user = User::factory()->create();

        $this->assertFalse(app(OtpService::class)->issue($user));
        $this->assertDatabaseCount('otp_codes', 1);
    }

    public function test_recipient_is_masked_for_display(): void
    {
        $user = User::factory()->make(['email' => 'juan.delacruz@alicia.gov.ph']);

        $this->assertSame('jua***uz@alicia.gov.ph', app(OtpService::class)->maskedRecipient($user));
    }

    public function test_expired_or_wrong_code_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        \App\Models\OtpCode::create([
            'user_id' => $user->id,
            'code_hash' => hash('sha256', '123456'),
            'purpose' => 'login',
            'expires_at' => now()->addMinutes(5),
        ]);

        $this->post('/otp/verify', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertNull(session('otp_verified'));
    }
}
