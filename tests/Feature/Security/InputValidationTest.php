<?php

namespace Tests\Feature\Security;

use App\Models\Department;
use App\Models\IntrusionLog;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\User;
use Database\Factories\EmployeeProfileFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\Support\SecurityEvidence;
use Tests\TestCase;

/**
 * Input Validation matrix — the functional test-plan row:
 *   "Empty, oversized, special-character, script, and invalid-format input
 *    across login and leave-form fields … Input rejected; no unhandled error;
 *    no script executed."
 *
 * Each of the five categories is exercised against the real login and leave
 * endpoints REPEAT times (default 50), so every result is a tally of 50
 * independent trials. For each trial the three criteria are checked:
 *   (a) rejected        — malformed input raises a validation error (or the IDS
 *                         blocks an attack payload); it is never accepted.
 *   (b) no 500          — the response is a clean validation redirect / block
 *                         page, never an unhandled "Server Error".
 *   (c) no script run   — script payloads are blocked by the IDS before storage
 *                         (and output is Blade-escaped), so nothing executes.
 *
 * The one deliberate exception is the special-character category: ordinary
 * punctuation in a free-text field (an apostrophe, a hyphen) is legitimate and
 * is ACCEPTED, not rejected — rejecting it would be a usability bug. That trial
 * asserts the input is neither blocked as an attack nor crashes the app.
 *
 * Evidence is written to storage/app/input-validation-evidence/ via
 * Tests\Support\SecurityEvidence and rendered by
 * scripts/security-evidence-report.php.
 */
class InputValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;
    private LeaveType $vl;
    private static bool $evidenceReset = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();

        $dept = Department::factory()->create();
        $this->vl = LeaveType::where('code', 'VL')->firstOrFail();
        $this->employee = $this->makeUser('employee');
        EmployeeProfileFactory::new()->create(['user_id' => $this->employee->id, 'department_id' => $dept->id]);
        LeaveBalance::create([
            'user_id' => $this->employee->id, 'leave_type_id' => $this->vl->id,
            'earned' => 20, 'used' => 0, 'balance' => 20,
        ]);

        SecurityEvidence::setNamespace('input-validation-evidence');
        if (! self::$evidenceReset) {
            SecurityEvidence::reset();
            self::$evidenceReset = true;
        }
    }

    private function iterations(): int
    {
        return max(1, (int) env('INPUT_VALIDATION_ITERATIONS', 50));
    }

    /** A fully valid leave payload; individual trials mutate one field. */
    private function validLeave(): array
    {
        return [
            'leave_type_id' => [$this->vl->id],
            'date_filed' => '2026-07-01',
            'date_mode' => 'pick',
            'start_date' => '2026-07-13',
            'end_date' => '2026-07-17',
            'purpose' => 'Family vacation',
            'applicant_signature' => $this->employee->name,
            'details' => ['location' => 'within_ph', 'location_specify' => 'Alicia'],
        ];
    }

    private function asGuest(): void
    {
        $this->app['auth']->logout();
        session()->forget('otp_verified');
    }

    private function asEmployee(): void
    {
        $this->actingAs($this->employee);
        session(['otp_verified' => true]);
    }

    /** True if the response carries validation errors on any of the given fields. */
    private function rejected(TestResponse $resp, array $fields): bool
    {
        try {
            $resp->assertSessionHasErrors($fields);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function no500(TestResponse $resp): bool
    {
        return $resp->status() !== 500;
    }

    // ============================================================== 1. empty

    public function test_empty_input_is_rejected(): void
    {
        $n = $this->iterations();
        for ($i = 1; $i <= $n; $i++) {
            $this->asGuest();
            $login = $this->post('/login', ['identifier' => '', 'password' => '']);
            $loginRejected = $this->rejected($login, ['identifier', 'password']) && $this->no500($login);

            $this->asEmployee();
            $leave = $this->post('/leave', []);
            $leaveRejected = $this->rejected($leave, ['leave_type_id', 'date_filed', 'start_date', 'end_date', 'applicant_signature'])
                && $this->no500($leave);

            $pass = $loginRejected && $leaveRejected;
            $this->assertTrue($pass, "login={$login->status()} leave={$leave->status()}");
            SecurityEvidence::trial('Empty Input Rejected', $i, $pass, [
                'login_required_errors' => $loginRejected,
                'leave_required_errors' => $leaveRejected,
                'no_server_error' => $this->no500($login) && $this->no500($leave),
            ]);
        }
    }

    // ========================================================== 2. oversized

    public function test_oversized_input_is_rejected(): void
    {
        $n = $this->iterations();
        for ($i = 1; $i <= $n; $i++) {
            $this->asGuest();
            $login = $this->post('/login', ['identifier' => str_repeat('a', 300), 'password' => 'x']);
            $loginRejected = $this->rejected($login, ['identifier']) && $this->no500($login);

            $this->asEmployee();
            $leave = $this->post('/leave', array_merge($this->validLeave(), [
                'purpose' => str_repeat('x', 1100),              // max:1000
                'applicant_signature' => str_repeat('y', 200),    // max:150
            ]));
            $leaveRejected = $this->rejected($leave, ['purpose', 'applicant_signature']) && $this->no500($leave);

            $pass = $loginRejected && $leaveRejected;
            $this->assertTrue($pass, "login={$login->status()} leave={$leave->status()}");
            SecurityEvidence::trial('Oversized Input Rejected', $i, $pass, [
                'login_maxlength_enforced' => $loginRejected,
                'leave_maxlength_enforced' => $leaveRejected,
            ]);
        }
    }

    // ================================================= 3. special characters

    /**
     * Ordinary punctuation in the free-text purpose field is legitimate and must
     * be accepted — the IDS is deliberately tuned so "Family emergency -- urgent"
     * is not treated as an attack. The trial passes when the input is neither
     * blocked (400) nor crashes (500), and raises no validation error on purpose.
     */
    public function test_special_characters_in_free_text_are_accepted(): void
    {
        $n = $this->iterations();
        $prose = [
            "Family emergency -- urgent, O'Brien & Sons",
            "Medical check-up -- follow-up next week",
            "Attending my sister's wedding (out of town)",
            "Leave re: house repair -- 50% done",
        ];
        for ($i = 1; $i <= $n; $i++) {
            $this->asEmployee();
            $text = $prose[$i % count($prose)];
            $leave = $this->post('/leave', array_merge($this->validLeave(), ['purpose' => $text]));

            $notBlocked = $leave->status() !== 400;
            $notCrashed = $this->no500($leave);
            $noPurposeError = ! $this->rejected($leave, ['purpose']);

            $pass = $notBlocked && $notCrashed && $noPurposeError;
            $this->assertTrue($pass, "status={$leave->status()} text=\"{$text}\"");
            SecurityEvidence::trial('Special-Character Input Accepted', $i, $pass, [
                'not_falsely_blocked' => $notBlocked,
                'no_server_error' => $notCrashed,
                'accepted_as_valid_text' => $noPurposeError,
            ]);
        }
    }

    // ============================================================= 4. script

    /**
     * Script payloads are caught by the IDS before they reach a controller (HTTP
     * 400 block page) and recorded as xss intrusion events, so nothing is ever
     * stored or rendered — "no script executed". Checked on the login identifier
     * and on the leave purpose + signature free-text fields.
     */
    public function test_script_input_is_blocked_and_logged(): void
    {
        $n = $this->iterations();
        $payloads = [
            '<script>alert(1)</script>',
            '"><img src=x onerror=alert(1)>',
            '<script>document.cookie</script>',
            'javascript:alert(document.domain)',
        ];
        for ($i = 1; $i <= $n; $i++) {
            IntrusionLog::where('category', 'xss')->delete();
            $payload = $payloads[$i % count($payloads)];

            $this->asGuest();
            $login = $this->post('/login', ['identifier' => $payload, 'password' => 'x']);

            $this->asEmployee();
            $leave = $this->post('/leave', array_merge($this->validLeave(), ['purpose' => $payload]));
            $sig = $this->post('/leave', array_merge($this->validLeave(), ['applicant_signature' => $payload]));

            // Each attack is blocked (400), never accepted (never a 302 to a saved
            // record) and never a 500; and an xss intrusion row is recorded.
            $blocked = $login->status() === 400 && $leave->status() === 400 && $sig->status() === 400;
            $noServerError = $this->no500($login) && $this->no500($leave) && $this->no500($sig);
            $logged = IntrusionLog::where('category', 'xss')->exists();
            // The block pages must not echo the raw executable payload back.
            $noRawScript = ! str_contains($login->getContent(), '<script>alert(1)</script>');

            $pass = $blocked && $noServerError && $logged && $noRawScript;
            $this->assertTrue($pass, "login={$login->status()} leave={$leave->status()} sig={$sig->status()} logged=".($logged ? 1 : 0));
            SecurityEvidence::trial('Script Input Blocked (XSS)', $i, $pass, [
                'blocked_400' => $blocked,
                'xss_event_logged' => $logged,
                'no_server_error' => $noServerError,
                'no_raw_script_reflected' => $noRawScript,
            ]);

            if ($i === $n) {
                SecurityEvidence::sample('Script Input Blocked (XSS)',
                    IntrusionLog::where('category', 'xss')->limit(5)
                        ->get(['category', 'severity', 'matched_rule', 'payload_excerpt', 'ip'])->toArray());
            }
        }
    }

    // ===================================================== 5. invalid format

    public function test_invalid_format_input_is_rejected(): void
    {
        $n = $this->iterations();
        for ($i = 1; $i <= $n; $i++) {
            $this->asEmployee();

            // Non-date in a date field.
            $badDate = $this->post('/leave', array_merge($this->validLeave(), ['start_date' => 'not-a-date']));
            $dateRejected = $this->rejected($badDate, ['start_date']) && $this->no500($badDate);

            // End before start.
            $backwards = $this->post('/leave', array_merge($this->validLeave(), [
                'start_date' => '2026-07-17', 'end_date' => '2026-07-13',
            ]));
            $orderRejected = $this->rejected($backwards, ['end_date']) && $this->no500($backwards);

            // Disallowed file type.
            $badFile = $this->post('/leave', array_merge($this->validLeave(), [
                'documents' => [UploadedFile::fake()->create('malware.exe', 20)],
            ]));
            $fileRejected = $this->rejected($badFile, ['documents.0']) && $this->no500($badFile);

            $pass = $dateRejected && $orderRejected && $fileRejected;
            $this->assertTrue($pass, "date={$badDate->status()} order={$backwards->status()} file={$badFile->status()}");
            SecurityEvidence::trial('Invalid-Format Input Rejected', $i, $pass, [
                'non_date_rejected' => $dateRejected,
                'end_before_start_rejected' => $orderRejected,
                'wrong_file_type_rejected' => $fileRejected,
            ]);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (class_exists(SecurityEvidence::class) && function_exists('storage_path')) {
            try {
                SecurityEvidence::setNamespace('input-validation-evidence');
                SecurityEvidence::writeSummary([
                    'system' => 'Cybersecurity Integrated Digital Leave Management System (LGU Alicia)',
                    'suite' => 'InputValidationTest',
                ]);
            } catch (\Throwable $e) {
                // best-effort
            }
        }
        parent::tearDownAfterClass();
    }
}
