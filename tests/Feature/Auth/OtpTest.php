<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
