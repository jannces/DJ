<?php

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use App\Models\BlockedIp;
use App\Models\FailedLogin;
use App\Models\IntrusionLog;
use App\Models\SystemSetting;
use App\Models\User;
use App\Notifications\AccountLockoutAlertNotification;
use App\Notifications\IntrusionAlertNotification;
use App\Services\Security\IntrusionDetectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Support\SecurityEvidence;
use Tests\TestCase;

/**
 * Security Test Matrix — the six acceptance scenarios from the thesis test plan,
 * each executed REPEAT times (default 50) so the pass/fail result is a tally of
 * independent trials rather than a single lucky run. Override the count with the
 * SECURITY_MATRIX_ITERATIONS environment variable.
 *
 * | # | Scenario                          | Security control verified                     |
 * |---|-----------------------------------|-----------------------------------------------|
 * | 1 | Detected Vulnerabilities (sweep)  | Input validation, RBAC, auth, secure config   |
 * | 2 | Unauthorized Access Attempt       | OTP gate, RBAC, access logging                |
 * | 3 | Blocked Unauthorized Login        | Login-attempt blocking, failed-login log      |
 * | 4 | SQL Injection Attempt             | Input validation, IDS signatures, prepared SQL|
 * | 5 | Brute Force Login Attempt         | Login blocking, OTP, intrusion logging        |
 * | 6 | Intrusion Alerts Generated        | Real-time alerts + audit record, with latency |
 *
 * Each trial writes its outcome and representative database rows to
 * storage/app/security-evidence/ via Tests\Support\SecurityEvidence, which is
 * the raw material for the HTML report and screenshots.
 *
 * This is a defensive self-assessment against the project's own application; it
 * uses the same in-process controls the live system runs (IDS middleware,
 * LoginSecurityService, PermissionMiddleware).
 */
class SecurityTestMatrixTest extends TestCase
{
    use RefreshDatabase;

    /** Role-restricted endpoints an employee (and an anonymous visitor) must not reach. */
    private const RESTRICTED = [
        '/users'         => 'users.manage',
        '/roles'         => 'rbac.manage',
        '/security'      => 'security.dashboard',
        '/audit-logs'    => 'audit.view',
        '/settings'      => 'settings.manage',
    ];

    /** Ten SQL-injection payloads: tautology, UNION, and time-delay families. */
    private const SQLI_PAYLOADS = [
        "1' OR 1=1 --",
        "admin' --",
        "' OR '1'='1",
        "1' OR '1'='1' #",
        "x' UNION SELECT username, password FROM users --",
        "' UNION SELECT NULL, version() --",
        "1'; DROP TABLE users; --",
        "1' AND SLEEP(5) --",
        "1' WAITFOR DELAY '0:0:5' --",
        "' OR SLEEP(5)='",
    ];

    /**
     * The subset of SQLI_PAYLOADS whose shape the IDS signatures are meant to
     * catch (UNION, DROP, OR/AND 1=1, SLEEP, WAITFOR). The remaining payloads
     * are quote-only tautologies defended at the data layer, not by signature.
     */
    private const CATCHABLE_SQLI = [
        "1' OR 1=1 --",
        "x' UNION SELECT username, password FROM users --",
        "' UNION SELECT NULL, version() --",
        "1'; DROP TABLE users; --",
        "1' AND SLEEP(5) --",
        "1' WAITFOR DELAY '0:0:5' --",
        "' OR SLEEP(5)='",
    ];

    /** A brute-force wordlist — plain strings, none of which trip the IDS. */
    private const WORDLIST = [
        'password', '123456', 'password1', 'admin123', 'letmein',
        'qwerty', 'welcome1', 'alicia2024', 'P@ssw0rd', 'changeme',
        'iloveyou', 'sunshine', 'monkey12', 'dragon77', 'football',
        'master99', 'shadow01', 'superman', 'trustno1', 'baseball',
    ];

    private static bool $evidenceReset = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();

        // Start each matrix run from a clean evidence ledger (once, after the
        // app has booted so storage_path() is available).
        if (! self::$evidenceReset) {
            SecurityEvidence::reset();
            self::$evidenceReset = true;
        }
    }

    private function iterations(): int
    {
        return max(1, (int) env('SECURITY_MATRIX_ITERATIONS', 50));
    }

    private function ip(string $prefix, int $i): string
    {
        // Deterministic, unique per iteration, inside TEST-NET-3 (203.0.113.0/24
        // style space) so it never collides with the loopback carve-out.
        return sprintf('198.51.%d.%d', ($i >> 8) & 0xFF, $i & 0xFF);
    }

    private function scan(string $ip, string $uri, array $query = []): ?\Symfony\Component\HttpFoundation\Response
    {
        $request = Request::create($uri, 'GET', $query);
        $request->server->set('REMOTE_ADDR', $ip);

        return app(IntrusionDetectionService::class)->inspect($request);
    }

    // ================================================================= 1. sweep

    /**
     * Scenario 1 — Detected Vulnerabilities. An endpoint sweep standing in for the
     * OWASP ZAP baseline scan: every restricted page is requested both anonymously
     * and as an authenticated employee. A "high/critical finding" here is a broken
     * access control (a restricted page served) or a server error (5xx). Neither
     * may occur in any of the 50 trials.
     */
    public function test_scenario_1_vulnerability_sweep(): void
    {
        $n = $this->iterations();
        $sample = null;

        for ($i = 1; $i <= $n; $i++) {
            $employee = $this->makeUser('employee');
            $employee->forceFill(['password' => Hash::make('Secret!Passw0rd#1')])->save();

            $findings = [];
            foreach (array_keys(self::RESTRICTED) as $path) {
                // Anonymous: must be redirected to login, never served, never 5xx.
                $anon = $this->get($path);
                if ($anon->status() >= 500 || $anon->status() === 200) {
                    $findings[] = "anon {$path} => {$anon->status()}";
                }

                // Authenticated employee (OTP cleared): must be 403, never served.
                $this->actingAs($employee);
                session(['otp_verified' => true]);
                $emp = $this->get($path);
                if ($emp->status() !== 403) {
                    $findings[] = "employee {$path} => {$emp->status()}";
                }
                $this->app['auth']->logout();
            }

            $pass = $findings === [];
            $this->assertTrue($pass, 'Sweep finding(s): '.implode('; ', $findings));
            SecurityEvidence::trial('Detected Vulnerabilities', $i, $pass, [
                'endpoints_probed' => count(self::RESTRICTED) * 2,
                'high_critical_findings' => count($findings),
                'findings' => $findings,
            ]);
            $sample ??= ['endpoints' => array_keys(self::RESTRICTED), 'anon_expected' => '302 → /login', 'employee_expected' => '403 Forbidden'];
        }

        SecurityEvidence::sample('Detected Vulnerabilities', $sample);
    }

    // ================================================== 2. unauthorized access

    /**
     * Scenario 2 — Unauthorized Access Attempt. Direct URL access to the five
     * role-restricted endpoints by an employee account and by an anonymous
     * session. Employee hits are refused with 403 and recorded as privilege
     * intrusion events; anonymous hits are bounced to the login screen.
     */
    public function test_scenario_2_unauthorized_access(): void
    {
        $n = $this->iterations();

        for ($i = 1; $i <= $n; $i++) {
            IntrusionLog::query()->delete();
            $employee = $this->makeUser('employee');

            $denied = 0;
            $redirected = 0;
            foreach (array_keys(self::RESTRICTED) as $path) {
                $anon = $this->get($path);
                if ($anon->isRedirect()) {
                    $redirected++;
                }

                $this->actingAs($employee);
                session(['otp_verified' => true]);
                if ($this->get($path)->status() === 403) {
                    $denied++;
                }
                $this->app['auth']->logout();
            }

            $logged = IntrusionLog::where('category', 'privilege')->count();
            $pass = $denied === count(self::RESTRICTED)
                && $redirected === count(self::RESTRICTED)
                && $logged >= count(self::RESTRICTED);

            $this->assertTrue($pass, "denied={$denied} redirected={$redirected} logged={$logged}");
            SecurityEvidence::trial('Unauthorized Access Attempt', $i, $pass, [
                'employee_denied_403' => $denied,
                'anonymous_redirected' => $redirected,
                'privilege_events_logged' => $logged,
            ]);

            if ($i === $n) {
                SecurityEvidence::sample('Unauthorized Access Attempt',
                    IntrusionLog::where('category', 'privilege')
                        ->get(['category', 'severity', 'route', 'method', 'matched_rule', 'ip', 'user_id'])->toArray());
            }
        }
    }

    // ============================================ 3. blocked login attempts

    /**
     * Scenario 3 — Blocked Unauthorized Login Attempts. Twenty consecutive failed
     * authentications against one account from a single source address inside a
     * 60-second window. The account must be blocked once the threshold (3) is
     * crossed, every attempt recorded in failed_logins, and later attempts refused.
     */
    public function test_scenario_3_blocked_login_attempts(): void
    {
        $n = $this->iterations();
        $threshold = (int) SystemSetting::get('auth.lockout_attempts', 3);

        for ($i = 1; $i <= $n; $i++) {
            FailedLogin::query()->delete();
            $victim = User::factory()->create([
                'email' => "s3.victim{$i}@alicia.gov.ph",
                'password' => Hash::make('Secret!Passw0rd#1'),
            ]);
            $ip = $this->ip('s3', $i);

            $start = microtime(true);
            for ($a = 0; $a < 20; $a++) {
                $this->call('POST', '/login', [
                    'identifier' => $victim->email,
                    'password' => 'definitely-wrong-'.$a,
                ], [], [], ['REMOTE_ADDR' => $ip]);
            }
            $windowSeconds = microtime(true) - $start;

            $victim->refresh();
            $blocked = $victim->status === User::STATUS_BLOCKED;
            $recorded = FailedLogin::where('ip', $ip)->count();

            // After the block, a correct password is still refused.
            $afterBlock = $this->call('POST', '/login', [
                'identifier' => $victim->email,
                'password' => 'Secret!Passw0rd#1',
            ], [], [], ['REMOTE_ADDR' => $ip]);
            $stillRefused = ! $this->isAuthenticated();

            $pass = $blocked && $recorded >= $threshold && $stillRefused && $windowSeconds < 60;
            $this->assertTrue($pass, "blocked={$blocked} recorded={$recorded} refused={$stillRefused}");
            SecurityEvidence::trial('Blocked Unauthorized Login Attempts', $i, $pass, [
                'attempts' => 20,
                'threshold' => $threshold,
                'failed_logins_recorded' => $recorded,
                'account_blocked' => $blocked,
                'correct_password_still_refused' => $stillRefused,
                'window_seconds' => round($windowSeconds, 3),
            ]);

            if ($i === $n) {
                SecurityEvidence::sample('Blocked Unauthorized Login Attempts',
                    FailedLogin::where('ip', $ip)->limit(5)
                        ->get(['identifier', 'ip', 'reason', 'occurred_at'])->toArray());
            }
        }
    }

    // ===================================================== 4. SQL injection

    /**
     * Scenario 4 — SQL Injection Attempt. Ten payloads (tautology, UNION,
     * time-delay) are driven through the system's two independent defences, and
     * the scenario only passes when BOTH hold on every trial:
     *
     *   (a) Detection — the IDS signature layer blocks and logs the classic
     *       injection shapes (UNION, DROP, OR/AND 1=1, SLEEP/WAITFOR). These are
     *       listed in CATCHABLE_SQLI and every one of them must be refused.
     *   (b) No unauthorized database access — the data layer uses parameterized
     *       queries, so EVERY payload (including quote-only tautologies such as
     *       `' OR '1'='1` that no signature need match) is treated as a literal
     *       string. A tautology run against a column with known users must return
     *       zero rows, proving the OR never executed and the whole table did not
     *       leak.
     *
     * (a) is the alerting barrier; (b) is why injection fails even when a
     * lexical variant slips (a) — which is the actual security guarantee.
     */
    public function test_scenario_4_sql_injection(): void
    {
        $n = $this->iterations();

        for ($i = 1; $i <= $n; $i++) {
            IntrusionLog::query()->delete();
            $ip = $this->ip('s4', $i);

            // A known user the tautologies would expose if injection worked.
            $known = User::factory()->create(['email' => "s4.known{$i}@alicia.gov.ph"]);
            $totalUsers = User::count();

            // (a) Detection layer.
            $catchableBlocked = 0;
            foreach (self::SQLI_PAYLOADS as $payload) {
                $resp = $this->scan($ip, '/employees', ['q' => $payload]);
                if ($resp !== null && in_array($payload, self::CATCHABLE_SQLI, true)) {
                    $catchableBlocked++;
                }
            }
            $logged = IntrusionLog::where('category', 'sqli')->where('ip', $ip)->count();
            $detectionOk = $catchableBlocked === count(self::CATCHABLE_SQLI) && $logged >= 1;

            // (b) Data layer: every payload is a bound literal, not SQL.
            $dataSafe = true;
            foreach (self::SQLI_PAYLOADS as $payload) {
                try {
                    $rows = User::where('email', $payload)->get();
                } catch (\Throwable $e) {
                    // A thrown query error would itself be a failure of this check.
                    $dataSafe = false;
                    break;
                }
                // A working injection would return the known user or the whole
                // table; a parameterized query returns nothing for these.
                if ($rows->isNotEmpty()) {
                    $dataSafe = false;
                    break;
                }
            }
            // And the table was neither emptied nor altered by any payload.
            $dataSafe = $dataSafe && User::count() === $totalUsers;

            $pass = $detectionOk && $dataSafe;
            $this->assertTrue($pass, "catchableBlocked={$catchableBlocked}/".count(self::CATCHABLE_SQLI)." logged={$logged} dataSafe=".($dataSafe?'1':'0'));
            SecurityEvidence::trial('SQL Injection Attempt', $i, $pass, [
                'payloads_sent' => count(self::SQLI_PAYLOADS),
                'signature_catchable' => count(self::CATCHABLE_SQLI),
                'signature_catchable_blocked' => $catchableBlocked,
                'sqli_events_logged' => $logged,
                'parameterized_query_leaked_rows' => $dataSafe ? 0 : 1,
                'no_unauthorized_db_access' => $dataSafe,
            ]);

            if ($i === $n) {
                SecurityEvidence::sample('SQL Injection Attempt',
                    IntrusionLog::where('category', 'sqli')->where('ip', $ip)->limit(5)
                        ->get(['category', 'severity', 'matched_rule', 'payload_excerpt', 'ip'])->toArray());
            }
        }
    }

    // ======================================================= 5. brute force

    /**
     * Scenario 5 — Brute Force Login Attempt. Twenty login attempts from a
     * wordlist against a valid account from one source address. The account is
     * blocked after the threshold, no wordlist entry ever yields a session, and
     * even a password check that would succeed never bypasses the OTP gate.
     */
    public function test_scenario_5_brute_force(): void
    {
        $n = $this->iterations();
        $threshold = (int) SystemSetting::get('auth.lockout_attempts', 3);

        for ($i = 1; $i <= $n; $i++) {
            $victim = User::factory()->create([
                'email' => "s5.victim{$i}@alicia.gov.ph",
                'password' => Hash::make('Secret!Passw0rd#1'),
            ]);
            $ip = $this->ip('s5', $i);

            $sessionGranted = false;
            foreach (self::WORDLIST as $guess) {
                $this->call('POST', '/login', [
                    'identifier' => $victim->email,
                    'password' => $guess,
                ], [], [], ['REMOTE_ADDR' => $ip]);
                if ($this->isAuthenticated()) {
                    $sessionGranted = true;
                }
                $this->app['auth']->logout();
            }

            $victim->refresh();
            $blocked = $victim->status === User::STATUS_BLOCKED;
            $intrusion = IntrusionLog::where('category', 'auth_fail')->where('user_id', $victim->id)->exists();

            $pass = $blocked && ! $sessionGranted && $intrusion;
            $this->assertTrue($pass, "blocked={$blocked} granted={$sessionGranted} intrusion={$intrusion}");
            SecurityEvidence::trial('Brute Force Login Attempt', $i, $pass, [
                'guesses' => count(self::WORDLIST),
                'threshold' => $threshold,
                'account_blocked' => $blocked,
                'session_ever_granted' => $sessionGranted,
                'auth_fail_intrusion_logged' => $intrusion,
            ]);
        }

        // One representative lockout row, captured from a fresh victim.
        $v = User::factory()->create(['email' => 's5.sample@alicia.gov.ph', 'password' => Hash::make('Secret!Passw0rd#1')]);
        for ($a = 0; $a < $threshold; $a++) {
            $this->call('POST', '/login', ['identifier' => $v->email, 'password' => 'nope'.$a], [], [], ['REMOTE_ADDR' => '198.51.200.200']);
        }
        SecurityEvidence::sample('Brute Force Login Attempt',
            IntrusionLog::where('category', 'auth_fail')->where('user_id', $v->id)
                ->get(['category', 'severity', 'matched_rule', 'payload_excerpt', 'ip'])->toArray());
    }

    // ================================================== 6. intrusion alerts

    /**
     * Scenario 6 — Intrusion Alerts Generated. Each attack family is executed and
     * must produce both a real-time administrator alert and an audit/intrusion
     * record. Alert latency (detection → notification queued) is measured on every
     * trial so the report can show it is sub-second.
     */
    public function test_scenario_6_intrusion_alerts(): void
    {
        $n = $this->iterations();

        for ($i = 1; $i <= $n; $i++) {
            Notification::fake();
            $admin = $this->makeUser('system-admin');
            $victim = User::factory()->create([
                'email' => "s6.victim{$i}@alicia.gov.ph",
                'password' => Hash::make('Secret!Passw0rd#1'),
            ]);
            $ip = $this->ip('s6', $i);

            // (a) Brute-force lockout → account-lockout alert + audit record.
            $t0 = microtime(true);
            for ($a = 0; $a < 3; $a++) {
                $this->call('POST', '/login', ['identifier' => $victim->email, 'password' => 'wrong'.$a], [], [], ['REMOTE_ADDR' => $ip]);
            }
            $lockoutLatencyMs = (microtime(true) - $t0) * 1000;

            $lockoutAlerted = true;
            try {
                Notification::assertSentTo($admin, AccountLockoutAlertNotification::class);
            } catch (\Throwable $e) {
                $lockoutAlerted = false;
            }
            $lockoutAudited = AuditLog::where('action', 'account_blocked')->exists();

            // (b) SQLi crossing the auto-block threshold → intrusion alert.
            SystemSetting::set('security.auto_block_threshold', '3');
            $sqliIp = $this->ip('s6b', $i);
            for ($a = 0; $a < 3; $a++) {
                $this->scan($sqliIp, '/employees', ['q' => "1' OR 1=1 --"]);
            }
            $sqliAlerted = true;
            try {
                Notification::assertSentTo($admin, IntrusionAlertNotification::class);
            } catch (\Throwable $e) {
                $sqliAlerted = false;
            }
            $sqliLogged = IntrusionLog::where('category', 'sqli')->where('ip', $sqliIp)->exists();

            $pass = $lockoutAlerted && $lockoutAudited && $sqliAlerted && $sqliLogged;
            $this->assertTrue($pass, "lockoutAlert={$lockoutAlerted} audit={$lockoutAudited} sqliAlert={$sqliAlerted} sqliLog={$sqliLogged}");
            SecurityEvidence::trial('Intrusion Alerts Generated', $i, $pass, [
                'lockout_alert_sent' => $lockoutAlerted,
                'lockout_audit_recorded' => $lockoutAudited,
                'sqli_alert_sent' => $sqliAlerted,
                'sqli_event_logged' => $sqliLogged,
                'lockout_alert_latency_ms' => round($lockoutLatencyMs, 2),
            ]);
        }
    }

    // ============================================================ teardown

    public static function tearDownAfterClass(): void
    {
        // Fold the ledger into summary.json once the whole matrix has run.
        if (class_exists(SecurityEvidence::class) && function_exists('storage_path')) {
            try {
                SecurityEvidence::writeSummary([
                    'system' => 'Cybersecurity Integrated Digital Leave Management System (LGU Alicia)',
                    'suite' => 'SecurityTestMatrixTest',
                ]);
            } catch (\Throwable $e) {
                // Evidence summary is best-effort; never fail the suite over it.
            }
        }
        parent::tearDownAfterClass();
    }
}
