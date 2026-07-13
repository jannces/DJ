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
