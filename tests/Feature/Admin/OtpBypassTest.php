<?php

namespace Tests\Feature\Admin;

use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Who may hand out a one-time sign-in code, and what it leaves behind.
 *
 * A code that lets someone past the second factor is worth as much as the
 * second factor itself, so the interesting assertions here are the refusals
 * and the audit row — not that the happy path returns a redirect.
 */
class OtpBypassTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        Mail::fake();
    }

    private function signedIn(string $role): User
    {
        $user = $this->makeUser($role);
        $this->actingAs($user);
        session(['otp_verified' => true]);

        return $user;
    }

    public function test_an_administrator_can_issue_one(): void
    {
        $admin = $this->signedIn('system-admin');
        $employee = User::factory()->create();

        $response = $this->post(route('users.otp-bypass', $employee));

        $response->assertRedirect();
        $this->assertDatabaseHas('otp_codes', [
            'user_id' => $employee->id,
            'issued_by_id' => $admin->id,
            'delivery_status' => OtpCode::DELIVERY_BYPASS,
        ]);
    }

    public function test_the_code_is_shown_to_the_administrator_exactly_once(): void
    {
        $this->signedIn('system-admin');
        $employee = User::factory()->create();

        $this->post(route('users.otp-bypass', $employee))
            ->assertSessionHas('status', fn ($status) => (bool) preg_match('/\b\d{6}\b/', $status));

        // It is never mailed, and the stored row keeps only the hash.
        Mail::assertNothingQueued();
        $stored = OtpCode::where('user_id', $employee->id)->latest('id')->firstOrFail();
        $this->assertSame(64, strlen($stored->code_hash));
    }

    public function test_an_ordinary_employee_cannot_issue_one(): void
    {
        $this->signedIn('employee');
        $target = User::factory()->create();

        $this->post(route('users.otp-bypass', $target))->assertForbidden();
        $this->assertDatabaseCount('otp_codes', 0);
    }

    public function test_a_department_head_cannot_issue_one(): void
    {
        $this->signedIn('department-head');

        $this->post(route('users.otp-bypass', User::factory()->create()))->assertForbidden();
    }

    public function test_hr_cannot_issue_one_either(): void
    {
        // HR manages people, not authentication.
        $this->signedIn('hr');

        $this->post(route('users.otp-bypass', User::factory()->create()))->assertForbidden();
    }

    public function test_issuing_one_is_written_to_the_audit_trail(): void
    {
        $admin = $this->signedIn('system-admin');
        $employee = User::factory()->create();

        $this->post(route('users.otp-bypass', $employee));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'otp_bypass_issued',
            'user_id' => $admin->id,
            'auditable_id' => $employee->id,
        ]);
    }

    public function test_a_signed_out_visitor_cannot_reach_it(): void
    {
        $this->post(route('users.otp-bypass', User::factory()->create()))->assertRedirect(route('login'));
    }
}
