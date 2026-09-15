<?php

namespace Tests\Feature\Security;

use App\Models\BlockedIp;
use App\Models\IntrusionLog;
use App\Models\SystemSetting;
use App\Services\Security\IntrusionDetectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class IntrusionDetectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
    }

    private function scan(string $uri, array $query = []): ?\Symfony\Component\HttpFoundation\Response
    {
        $request = Request::create($uri, 'GET', $query);
        $request->server->set('REMOTE_ADDR', '203.0.113.9');

        return app(IntrusionDetectionService::class)->inspect($request);
    }

    public function test_it_detects_sql_injection_attempts(): void
    {
        $response = $this->scan('/employees', ['q' => "1' OR 1=1 --"]);
        $this->assertNotNull($response);
        $this->assertDatabaseHas('intrusion_logs', ['category' => 'sqli', 'ip' => '203.0.113.9']);
    }

    public function test_it_detects_xss_attempts(): void
    {
        $this->scan('/search', ['q' => '<script>document.cookie</script>']);
        $this->assertDatabaseHas('intrusion_logs', ['category' => 'xss']);
    }

    public function test_it_detects_directory_traversal(): void
    {
        $this->scan('/download', ['file' => '../../../../etc/passwd']);
        $this->assertDatabaseHas('intrusion_logs', ['category' => 'traversal']);
    }

    /**
     * Regression: ordinary prose used to trip the SQL-comment patterns. A leave
     * reason containing "--" produced a 400, a high-severity intrusion record,
     * and after the threshold a 24-hour IP block for a legitimate applicant.
     *
     * @dataProvider benignProse
     */
    public function test_free_text_prose_is_not_treated_as_an_attack(string $prose): void
    {
        $request = Request::create('/leave', 'POST', ['purpose' => $prose]);
        $request->server->set('REMOTE_ADDR', '203.0.113.9');

        $response = app(IntrusionDetectionService::class)->inspect($request);

        $this->assertNull($response, "Blocked benign text: {$prose}");
        $this->assertSame(0, IntrusionLog::count());
    }

    public static function benignProse(): array
    {
        return [
            'double hyphen' => ['Family emergency -- urgent, will return Monday'],
            'comment marks' => ['Attending my sister/*s*/ wedding'],
            'dashes' => ['Medical check-up -- follow-up next week'],
            'null-ish text' => ['Reason: %00 percent attendance issue'],
        ];
    }

    /** Free-text fields are still scanned for payloads with no innocent reading. */
    public function test_real_payloads_in_free_text_are_still_blocked(): void
    {
        foreach ([
            'UNION SELECT password FROM users',
            '<script>document.cookie</script>',
            'read /etc/passwd please',
            "anything OR 1=1",
        ] as $payload) {
            IntrusionLog::query()->delete();
            $request = Request::create('/leave', 'POST', ['purpose' => $payload]);
            $request->server->set('REMOTE_ADDR', '203.0.113.9');

            $this->assertNotNull(
                app(IntrusionDetectionService::class)->inspect($request),
                "Missed payload in free text: {$payload}",
            );
        }
    }

    /** A payload in the URL keeps the full, stricter signature set. */
    public function test_sql_comment_in_the_query_string_is_still_blocked(): void
    {
        $response = $this->scan('/employees', ['q' => "admin' --  "]);

        $this->assertNotNull($response);
        $this->assertDatabaseHas('intrusion_logs', ['category' => 'sqli']);
    }

    public function test_it_ignores_benign_requests(): void
    {
        $response = $this->scan('/leave', ['status' => 'approved']);
        $this->assertNull($response);
        $this->assertSame(0, IntrusionLog::count());
    }

    public function test_repeated_events_auto_block_the_ip(): void
    {
        SystemSetting::set('security.auto_block_threshold', '3');

        for ($i = 0; $i < 3; $i++) {
            $this->scan('/x', ['q' => "1' OR 1=1 --"]);
        }

        $this->assertTrue(BlockedIp::currentlyActive()->where('ip', '203.0.113.9')->exists());
        $this->assertDatabaseHas('audit_logs', ['action' => 'ip_auto_blocked']);
    }

    public function test_blocked_ip_middleware_rejects_the_request(): void
    {
        BlockedIp::create(['ip' => '198.51.100.7', 'reason' => 'test', 'source' => 'manual', 'active' => true]);

        $response = $this->call('GET', '/login', [], [], [], ['REMOTE_ADDR' => '198.51.100.7']);
        $response->assertStatus(403)->assertSee('Access blocked');
    }

    public function test_ids_can_be_disabled_via_settings(): void
    {
        SystemSetting::set('security.ids_enabled', '0');
        $response = $this->scan('/x', ['q' => "1' OR 1=1 --"]);
        $this->assertNull($response);
        $this->assertSame(0, IntrusionLog::count());
    }

    public function test_loopback_ip_is_never_auto_blocked(): void
    {
        \App\Models\SystemSetting::set('security.auto_block_threshold', '3');

        // Loopback triggers many events but must never be blocked.
        for ($i = 0; $i < 6; $i++) {
            $request = \Illuminate\Http\Request::create('/x', 'GET', ['q' => "1' OR 1=1 --"]);
            $request->server->set('REMOTE_ADDR', '127.0.0.1');
            app(\App\Services\Security\IntrusionDetectionService::class)->inspect($request);
        }

        $this->assertFalse(\App\Models\BlockedIp::query()->where('ip', '127.0.0.1')->where('active', true)->exists());
    }

    public function test_blocked_ip_middleware_ignores_a_block_on_loopback(): void
    {
        // Even a manual block row on loopback is bypassed (fail-safe for the admin/server).
        \App\Models\BlockedIp::create(['ip' => '127.0.0.1', 'reason' => 'test', 'source' => 'manual', 'active' => true]);

        $response = $this->call('GET', '/login', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $response->assertOk();
    }
}
