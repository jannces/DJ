<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Juan Dela Cruz',
            'username' => 'juandc',
            'email' => 'juan.delacruz@alicia.gov.ph',
            'employee_no' => 'EMP-0001',
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'salary' => 25000,
            'employment_status' => 'permanent',
        ], $overrides);
    }

    public function test_new_accounts_start_with_the_configured_default_password(): void
    {
        $this->actingAs($this->makeUser('super-admin'));
        session(['otp_verified' => true]);

        $this->post('/users', $this->payload())->assertRedirect(route('users.index'));

        $created = User::where('username', 'juandc')->firstOrFail();
        $this->assertTrue(Hash::check('OneAlicia123', $created->password));
        $this->assertTrue($created->must_change_password);
    }

    public function test_the_default_password_can_be_overridden_by_configuration(): void
    {
        config(['auth.default_new_user_password' => 'Sample!Default2026']);
        $this->actingAs($this->makeUser('super-admin'));
        session(['otp_verified' => true]);

        $this->post('/users', $this->payload(['username' => 'otheruser', 'email' => 'other@alicia.gov.ph', 'employee_no' => 'EMP-0002']));

        $this->assertTrue(Hash::check('Sample!Default2026', User::where('username', 'otheruser')->firstOrFail()->password));
    }

    public function test_the_default_password_is_never_written_to_the_audit_log(): void
    {
        $this->actingAs($this->makeUser('super-admin'));
        session(['otp_verified' => true]);

        $this->post('/users', $this->payload());

        $log = AuditLog::where('action', 'user_created')->latest('id')->firstOrFail();
        $this->assertStringNotContainsString('OneAlicia123', json_encode($log->new_values));
    }
}
