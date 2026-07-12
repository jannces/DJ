<?php

namespace Tests\Feature\Auth;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
    }

    public function test_login_page_renders(): void
    {
        $this->get('/login')->assertOk()->assertSee('Sign in');
    }

    public function test_valid_credentials_trigger_otp_challenge(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Secret!Passw0rd#1')]);

        $this->post('/login', [
            'identifier' => $user->email,
            'password' => 'Secret!Passw0rd#1',
        ])->assertRedirect('/otp');

        $this->assertDatabaseHas('otp_codes', ['user_id' => $user->id, 'purpose' => 'login']);
    }

    public function test_three_failed_attempts_block_the_account_for_24_hours(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Secret!Passw0rd#1')]);

        for ($i = 0; $i < 3; $i++) {
            $this->post('/login', ['identifier' => $user->email, 'password' => 'wrong-password']);
        }

        $user->refresh();
        $this->assertSame(User::STATUS_BLOCKED, $user->status);
        $this->assertNotNull($user->blocked_until);
        $this->assertTrue($user->blocked_until->between(now()->addHours(23), now()->addHours(25)));
        $this->assertDatabaseCount('failed_logins', 3);
        $this->assertDatabaseHas('intrusion_logs', ['category' => 'auth_fail', 'user_id' => $user->id]);
    }

    public function test_blocked_account_cannot_log_in_even_with_correct_password(): void
    {
        $user = User::factory()->blocked()->create(['password' => Hash::make('Secret!Passw0rd#1')]);

        $this->post('/login', [
            'identifier' => $user->email,
            'password' => 'Secret!Passw0rd#1',
        ])->assertSessionHasErrors('identifier');

        $this->assertGuest();
    }

    public function test_otp_gate_blocks_dashboard_until_verified(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // otp_verified flag not set → redirected to OTP screen
        $this->get('/dashboard')->assertRedirect('/otp');
    }

    public function test_login_can_be_completed_when_otp_is_disabled(): void
    {
        SystemSetting::set('auth.otp_enabled', '0');
        $user = User::factory()->create(['password' => Hash::make('Secret!Passw0rd#1')]);

        $this->post('/login', [
            'identifier' => $user->username,
            'password' => 'Secret!Passw0rd#1',
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
    }
}
