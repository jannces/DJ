<?php

namespace Tests\Feature\Api;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
    }

    public function test_api_login_without_otp_returns_a_token(): void
    {
        SystemSetting::set('auth.otp_enabled', '0');
        $user = User::factory()->create(['password' => Hash::make('Secret!Passw0rd#1')]);

        $response = $this->postJson('/api/v1/auth/login', [
            'identifier' => $user->email,
            'password' => 'Secret!Passw0rd#1',
        ]);

        $response->assertOk()->assertJsonStructure(['otp_required', 'token']);
        $this->assertFalse($response->json('otp_required'));
    }

    public function test_api_login_with_otp_requires_verification(): void
    {
        SystemSetting::set('auth.otp_enabled', '1');
        $user = User::factory()->create(['password' => Hash::make('Secret!Passw0rd#1')]);

        $response = $this->postJson('/api/v1/auth/login', [
            'identifier' => $user->email,
            'password' => 'Secret!Passw0rd#1',
        ]);

        $response->assertOk()->assertJson(['otp_required' => true]);
        $this->assertDatabaseHas('otp_codes', ['user_id' => $user->id]);
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_authenticated_token_can_read_profile(): void
    {
        $user = $this->makeUser('employee');
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('email', $user->email);
    }

    public function test_openapi_spec_and_docs_are_available(): void
    {
        $this->get('/api/documentation')->assertOk()->assertSee('swagger-ui', false);
        $this->assertFileExists(public_path('openapi.yaml'));
    }
}
