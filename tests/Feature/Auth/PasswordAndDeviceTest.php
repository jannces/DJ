<?php

namespace Tests\Feature\Auth;

use App\Models\AuthorizedDevice;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordAndDeviceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
    }

    public function test_password_change_enforces_strength_and_clears_flag(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('OldSecret!Pass1'),
            'must_change_password' => true,
        ]);
        $this->actingAs($user);
        session(['otp_verified' => true]);

        // Weak password rejected
        $this->from('/change-password')->post('/change-password', [
            'current_password' => 'OldSecret!Pass1',
            'password' => 'weak',
            'password_confirmation' => 'weak',
        ])->assertSessionHasErrors('password');

        // Strong password accepted
        $this->post('/change-password', [
            'current_password' => 'OldSecret!Pass1',
            'password' => 'BrandN3w&Strong!Pw',
            'password_confirmation' => 'BrandN3w&Strong!Pw',
        ])->assertRedirect('/dashboard');

        $this->assertFalse($user->fresh()->must_change_password);
        $this->assertTrue(Hash::check('BrandN3w&Strong!Pw', $user->fresh()->password));
    }

    public function test_forgot_password_never_reveals_whether_the_email_exists(): void
    {
        $this->post('/forgot-password', ['email' => 'nobody@example.com'])
            ->assertSessionHas('status');
        // Uniform message regardless of existence.
        $this->post('/forgot-password', ['email' => 'nobody2@example.com'])
            ->assertSessionHasNoErrors();
    }

    public function test_device_enforcement_blocks_unregistered_devices(): void
    {
        SystemSetting::set('security.device_enforcement', '1');

        // 127.0.0.1 is seeded as authorized in the security seeder? No — that's CoreUserSeeder.
        AuthorizedDevice::create(['ip_address' => '127.0.0.1', 'hostname' => 'test', 'status' => 'active']);

        // Registered device (test client uses 127.0.0.1) passes through to login.
        $this->get('/login')->assertOk();

        // An unregistered IP is rejected with the branded device page.
        $this->call('GET', '/login', [], [], [], ['REMOTE_ADDR' => '10.10.10.10'])
            ->assertStatus(403)
            ->assertSee('Device not authorized');

        $this->assertDatabaseHas('intrusion_logs', ['category' => 'device', 'ip' => '10.10.10.10']);
    }
}
