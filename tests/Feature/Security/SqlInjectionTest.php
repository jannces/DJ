<?php

namespace Tests\Feature\Security;

use App\Models\Department;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * SQL injection, proved rather than asserted.
 *
 * The system's defence is that no query is ever assembled from a string. Every
 * value a person can supply reaches the database as a bound parameter, so a
 * payload is compared as a value and never parsed as SQL. The IDS sits in
 * front of that as a second layer, but the parameterisation is the one that
 * matters: a signature set can be evaded, bindings cannot.
 *
 * These tests fire real payloads at every input that reaches a query and check
 * the tables are still standing afterwards. A drop, a leak or a bypass would
 * show up as changed data, not as a changed page.
 */
class SqlInjectionTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<int,array{0:string}> */
    public static function payloads(): array
    {
        return [
            ["' OR '1'='1"],
            ["'; DROP TABLE users; --"],
            ["1' UNION SELECT password FROM users --"],
            ["admin'--"],
            ["' OR 1=1 --"],
            ["\" OR \"\"=\""],
            ["1; DELETE FROM leave_requests"],
            ["' AND SLEEP(5) --"],
            ["%' OR '1'='1"],
            ["\\' OR 1=1 --"],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        SystemSetting::set('security.device_enforcement', false);
        // The IDS blocks most of these before the controller sees them, which
        // is the point of having it -- but it is not what is under test here.
        // Turned off, every payload reaches the query layer, which is where
        // the real defence has to hold.
        SystemSetting::set('security.ids_enabled', false);
    }

    private function asHr(): User
    {
        $user = $this->makeUser('hr');
        $this->actingAs($user);
        session(['otp_verified' => true]);

        return $user;
    }

    private function asAdmin(): User
    {
        $user = $this->makeUser('system-admin');
        $this->actingAs($user);
        session(['otp_verified' => true]);

        return $user;
    }

    /**
     * @dataProvider payloads
     *
     * Every list's search and filter inputs, in one pass. A payload that was
     * concatenated rather than bound would drop a table or return rows it was
     * never entitled to; both show up here.
     */
    public function test_search_and_filter_inputs_cannot_reach_the_parser(string $payload): void
    {
        $this->asAdmin();
        Position::create(['title' => 'Municipal Treasurer', 'salary_grade' => 'SG 24']);
        Department::create(['name' => 'Treasury', 'code' => 'MTO']);
        $before = User::count();

        $urls = [
            '/users?q='.urlencode($payload),
            '/users?status='.urlencode($payload),
            '/devices?q='.urlencode($payload),
            '/audit-logs?action='.urlencode($payload),
            '/audit-logs?user='.urlencode($payload),
            '/activity-logs?user='.urlencode($payload),
            '/security/intrusions?category='.urlencode($payload),
            '/security/intrusions?severity='.urlencode($payload),
            '/security/intrusions?ip='.urlencode($payload),
        ];

        foreach ($urls as $url) {
            $response = $this->get($url);

            $this->assertContains($response->getStatusCode(), [200, 302, 403],
                $url.' produced a server error, which is how a broken query announces itself');
        }

        $this->assertTrue(Schema::hasTable('users'), 'a table was dropped');
        $this->assertTrue(Schema::hasTable('leave_requests'), 'a table was dropped');
        $this->assertSame($before, User::count(), 'rows appeared or vanished');
    }

    /**
     * @dataProvider payloads
     */
    public function test_the_hr_lists_hold_too(string $payload): void
    {
        $this->asHr();
        $before = User::count();

        foreach ([
            '/employees?q='.urlencode($payload),
            '/employees?department='.urlencode($payload),
            '/balances?q='.urlencode($payload),
            '/all-leave?status='.urlencode($payload),
            '/all-leave?type='.urlencode($payload),
        ] as $url) {
            $this->assertContains($this->get($url)->getStatusCode(), [200, 302, 403], $url);
        }

        $this->assertTrue(Schema::hasTable('users'));
        $this->assertSame($before, User::count());
    }

    /**
     * A payload in a search box must be treated as the text it is: something
     * to look for, matching nothing, rather than a condition that matches
     * everything. `' OR '1'='1` returning the whole table is the classic tell.
     */
    public function test_a_payload_matches_nothing_rather_than_everything(): void
    {
        $this->asHr();
        Position::create(['title' => 'Municipal Treasurer', 'salary_grade' => 'SG 24']);
        Position::create(['title' => 'Administrative Aide I', 'salary_grade' => 'SG 1']);

        $html = $this->get('/positions?q='.urlencode("' OR '1'='1"))->assertOk()->getContent();

        // Positions has no search box, so this proves the unrelated parameter
        // is simply ignored; the paged list is unchanged either way.
        $this->assertStringContainsString('Municipal Treasurer', $html);

        // Employees does search, and must come back empty for a payload.
        $html = $this->get('/employees?q='.urlencode("' OR '1'='1"))->assertOk()->getContent();
        $this->assertStringContainsString('No employees', $html,
            "the payload was evaluated as a condition, not compared as a value");
    }

    /**
     * Written values, not just read ones. A payload stored and read back must
     * come back byte for byte -- if it comes back changed, something built a
     * statement out of it.
     */
    public function test_a_payload_stored_in_a_record_survives_unchanged(): void
    {
        $this->asHr();
        $payload = "Robert'); DROP TABLE positions; --";

        $this->post('/positions', ['title' => $payload, 'salary_grade' => 'SG 1'])
            ->assertRedirect();

        $this->assertTrue(Schema::hasTable('positions'), 'little Bobby Tables got through');
        $this->assertDatabaseHas('positions', ['title' => $payload]);
        $this->assertSame($payload, Position::where('title', $payload)->first()?->title,
            'the stored value was mangled, which means it was not bound');
    }

    /**
     * The leave form is the largest untrusted surface in the system and its
     * free-text fields are deliberately exempt from the strictest IDS
     * signatures, so prose is not mistaken for an attack. That exemption is
     * only safe because the query layer never parses the value.
     */
    public function test_free_text_on_a_leave_application_is_stored_not_executed(): void
    {
        $user = $this->makeUser('employee');
        $this->actingAs($user);
        session(['otp_verified' => true]);
        SystemSetting::set('security.ids_enabled', true);

        $type = LeaveType::where('code', 'VL')->firstOrFail();
        $payload = "Family emergency -- urgent; DROP TABLE leave_requests; --";

        $this->post('/leave', [
            'leave_type_id' => $type->id,
            'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addWeek()->toDateString(),
            'purpose' => $payload,
            'commutation' => 'not_requested',
        ]);

        $this->assertTrue(Schema::hasTable('leave_requests'));
    }

    /**
     * The one place a value is interpolated into SQL rather than bound is
     * Role::scopeAssignable's CASE ordering, and it is built from a class
     * constant. This fails the day someone points it at input.
     */
    public function test_the_only_interpolated_sql_is_built_from_a_constant(): void
    {
        $source = file_get_contents(app_path('Models/Role.php'));

        $this->assertMatchesRegularExpression(
            '/orderByRaw\(\s*.CASE slug .\s*\.collect\(self::ASSIGNABLE\)/',
            $source,
            'the ordering is no longer built from the fixed role list'
        );
    }

    /**
     * Nothing anywhere hands a value to a raw SQL fragment.
     *
     * A raw call is accepted only when its first argument is a plain string
     * literal that ends there — no interpolated `{$var}`, and no `.` after the
     * closing quote, which is how a concatenated fragment would start. That is
     * the shape a binding cannot be smuggled past.
     *
     * The one deliberate exception is Role::scopeAssignable, which builds a
     * CASE ordering out of a class constant; the test above pins it to that
     * constant so it fails the day someone points it at input.
     */
    public function test_no_raw_sql_anywhere_is_built_from_a_value(): void
    {
        $offenders = [];
        $raw = 'DB::raw|whereRaw|selectRaw|havingRaw|orderByRaw|groupByRaw|joinRaw|fromRaw|DB::statement|DB::select';

        foreach ($this->phpFilesIn(app_path()) as $file) {
            foreach (file($file) as $number => $line) {
                if (! preg_match('/(?:'.$raw.')\(\s*(.*)$/', $line, $call)) {
                    continue;
                }
                // A whole, self-contained string literal as the first argument.
                $literal = "/^(['\"])((?:\\\\.|(?!\\1).)*)\\1\s*[,)]/";

                $safe = preg_match($literal, $call[1], $m)
                    && ! str_contains($m[2], '$');

                if (! $safe && ! str_contains($line, 'self::ASSIGNABLE')) {
                    $offenders[] = basename($file).':'.($number + 1).'  '.trim($line);
                }
            }
        }

        $this->assertSame([], $offenders,
            'these build raw SQL from a value; every value must be a binding');
    }

    /** Mass assignment is closed everywhere, so a stray field cannot ride in. */
    public function test_every_model_declares_what_may_be_filled(): void
    {
        $offenders = [];

        foreach (glob(app_path('Models/*.php')) as $file) {
            $source = file_get_contents($file);
            if (! str_contains($source, 'protected $fillable')
                || preg_match('/\$guarded\s*=\s*\[\s*\]/', $source)) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame([], $offenders);
    }

    /** A sanity check that the suite is testing a real database, not a stub. */
    public function test_the_database_is_really_answering(): void
    {
        $this->assertNotEmpty(DB::select('select 1 as ok'));
    }

    /** @return iterable<string> */
    private function phpFilesIn(string $dir): iterable
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                yield $file->getPathname();
            }
        }
    }
}
