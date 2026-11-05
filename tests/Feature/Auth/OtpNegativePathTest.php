<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\SecurityEvidence;
use Tests\TestCase;

/**
 * OTP Negative-Path matrix — the functional test-plan row:
 *   "Login with valid credentials but omitted, incorrect, expired, or reused
 *    OTP … Access denied in all cases; each attempt logged."
 *
 * Each of the four failure modes is exercised REPEAT times (default 50), so the
 * result is a tally of 50 independent trials. Every trial asserts that access to
 * the dashboard is denied (the otp.verified gate holds), and — for the three
 * cases where a code is actually submitted — that an `otp_failed` row is written
 * to the audit trail.
 *
 *   omitted   — no code entered; reaching /dashboard bounces back to /otp.
 *   incorrect — wrong 6-digit code → rejected + logged.
 *   expired   — correct code past its 5-minute TTL → rejected + logged.
 *   reused    — a code already consumed (single-use) → rejected + logged.
 *
 * Evidence is written to storage/app/otp-evidence/ via Tests\Support\SecurityEvidence.
 */
class OtpNegativePathTest extends TestCase
{
    use RefreshDatabase;

    private static bool $evidenceReset = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore(); // OTP is enabled by default (auth.otp_enabled = 1)

        SecurityEvidence::setNamespace('otp-evidence');
        if (! self::$evidenceReset) {
            SecurityEvidence::reset();
            self::$evidenceReset = true;
        }
    }

    private function iterations(): int
    {
        return max(1, (int) env('OTP_ITERATIONS', 50));
    }

    /** A login OTP for $user with a known plaintext code; overrides tweak state. */
    private function makeCode(User $user, string $code = '123456', array $overrides = []): OtpCode
    {
        return OtpCode::create(array_merge([
            'user_id' => $user->id,
            'code_hash' => hash('sha256', $code),
            'purpose' => 'login',
            'expires_at' => now()->addMinutes(5),
            'consumed_at' => null,
            'attempts' => 0,
            'ip' => '127.0.0.1',
        ], $overrides));
    }

    /** A freshly-authenticated user that has NOT cleared the OTP gate. */
    private function pendingUser(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        session()->forget('otp_verified');

        return $user;
    }

    /** Access is denied while the gate is unmet: /dashboard redirects to /otp. */
    private function accessDenied(): bool
    {
        return $this->get('/dashboard')->isRedirect(route('otp.show'));
    }

    // ============================================================ 1. omitted

    public function test_omitted_otp_is_denied(): void
    {
        $n = $this->iterations();
        for ($i = 1; $i <= $n; $i++) {
            $this->pendingUser();

            // No code entered at all — the gate must refuse the dashboard.
            $denied = $this->accessDenied();

            $this->assertTrue($denied, 'dashboard served without OTP verification');
            SecurityEvidence::trial('Omitted OTP Denied', $i, $denied, [
                'dashboard_redirected_to_otp' => $denied,
                'note' => 'no code submitted, so no otp_failed row — denied by the gate',
            ]);
        }
    }

    // ========================================================== 2. incorrect

    public function test_incorrect_otp_is_rejected_and_logged(): void
    {
        $n = $this->iterations();
        for ($i = 1; $i <= $n; $i++) {
            $user = $this->pendingUser();
            $this->makeCode($user, '123456');
            $before = AuditLog::where('action', 'otp_failed')->count();

            $resp = $this->post('/otp/verify', ['code' => '999999']); // wrong, but valid shape
            $rejected = $this->rejectedOtp($resp);
            $logged = AuditLog::where('action', 'otp_failed')->count() > $before;
            $denied = $this->accessDenied();

            $pass = $rejected && $logged && $denied;
            $this->assertTrue($pass, "rejected={$this->b($rejected)} logged={$this->b($logged)} denied={$this->b($denied)}");
            SecurityEvidence::trial('Incorrect OTP Rejected', $i, $pass, [
                'code_rejected' => $rejected, 'otp_failed_logged' => $logged, 'access_denied' => $denied,
            ]);
        }
        $this->captureSample();
    }

    // ============================================================ 3. expired

    public function test_expired_otp_is_rejected_and_logged(): void
    {
        $n = $this->iterations();
        for ($i = 1; $i <= $n; $i++) {
            $user = $this->pendingUser();
            // Correct code, but already past its TTL.
            $this->makeCode($user, '123456', ['expires_at' => now()->subMinute()]);
            $before = AuditLog::where('action', 'otp_failed')->count();

            $resp = $this->post('/otp/verify', ['code' => '123456']);
            $rejected = $this->rejectedOtp($resp);
            $logged = AuditLog::where('action', 'otp_failed')->count() > $before;
            $denied = $this->accessDenied();

            $pass = $rejected && $logged && $denied;
            $this->assertTrue($pass, "rejected={$this->b($rejected)} logged={$this->b($logged)} denied={$this->b($denied)}");
            SecurityEvidence::trial('Expired OTP Rejected', $i, $pass, [
                'code_rejected' => $rejected, 'otp_failed_logged' => $logged, 'access_denied' => $denied,
            ]);
        }
    }

    // ============================================================= 4. reused

    public function test_reused_otp_is_rejected_and_logged(): void
    {
        $n = $this->iterations();
        for ($i = 1; $i <= $n; $i++) {
            $user = $this->pendingUser();
            // Correct code, but already consumed once (single-use).
            $this->makeCode($user, '123456', ['consumed_at' => now()]);
            $before = AuditLog::where('action', 'otp_failed')->count();

            $resp = $this->post('/otp/verify', ['code' => '123456']);
            $rejected = $this->rejectedOtp($resp);
            $logged = AuditLog::where('action', 'otp_failed')->count() > $before;
            $denied = $this->accessDenied();

            $pass = $rejected && $logged && $denied;
            $this->assertTrue($pass, "rejected={$this->b($rejected)} logged={$this->b($logged)} denied={$this->b($denied)}");
            SecurityEvidence::trial('Reused OTP Rejected', $i, $pass, [
                'code_rejected' => $rejected, 'otp_failed_logged' => $logged, 'access_denied' => $denied,
            ]);
        }
    }

    // ------------------------------------------------------------- helpers

    private function rejectedOtp(\Illuminate\Testing\TestResponse $resp): bool
    {
        if ($resp->status() === 500) {
            return false;
        }
        // Never verified: the session flag stays false and errors are flashed.
        try {
            $resp->assertSessionHasErrors('code');
        } catch (\Throwable $e) {
            return false;
        }

        return session('otp_verified') !== true;
    }

    private function b(bool $v): string
    {
        return $v ? '1' : '0';
    }

    private function captureSample(): void
    {
        SecurityEvidence::sample('Incorrect OTP Rejected',
            AuditLog::where('action', 'otp_failed')->latest('id')->limit(5)
                ->get(['action', 'user_id', 'ip', 'created_at'])->toArray());
    }

    public static function tearDownAfterClass(): void
    {
        if (class_exists(SecurityEvidence::class) && function_exists('storage_path')) {
            try {
                SecurityEvidence::setNamespace('otp-evidence');
                SecurityEvidence::writeSummary([
                    'system' => 'Cybersecurity Integrated Digital Leave Management System (LGU Alicia)',
                    'suite' => 'OtpNegativePathTest',
                ]);
            } catch (\Throwable $e) {
                // best-effort
            }
        }
        parent::tearDownAfterClass();
    }
}
