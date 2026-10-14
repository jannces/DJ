<?php

namespace Tests\Feature\Security;

use App\Models\BlockedIp;
use App\Models\IntrusionLog;
use App\Models\SystemSetting;
use App\Services\Security\IntrusionDetectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The request-rate detector.
 *
 * Its key used to be `ids.rate.{ip}` with a lifetime that every request
 * rewrote, so it expired only after a full minute of complete silence from
 * that address. The bell polls every fifteen seconds on every open tab, so
 * silence never came: the count climbed past the limit and then flagged every
 * request from that address for as long as the tab stayed open — 284 identical
 * events in the log, one every fifteen seconds.
 *
 * The consequence was not just a noisy log. Each of those events feeds
 * maybeAutoBlock(), which trips at five in ten minutes, so an employee on the
 * LAN earned a 24-hour IP block about a minute into the stuck state, for
 * leaving the leave portal open. Loopback is trusted, so the one machine it
 * never happened to was the administrator's.
 */
class RateAnomalyTest extends TestCase
{
    use RefreshDatabase;

    private IntrusionDetectionService $ids;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->ids = app(IntrusionDetectionService::class);
        SystemSetting::set('security.rate_limit_per_minute', 5);
    }

    private function requestFrom(string $ip, string $path = '/dashboard'): Request
    {
        return Request::create($path, 'GET', server: ['REMOTE_ADDR' => $ip]);
    }

    private function hit(string $ip, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->ids->inspect($this->requestFrom($ip));
        }
    }

    public function test_a_burst_over_the_limit_is_still_caught(): void
    {
        $this->hit('192.168.1.50', 8);

        $this->assertGreaterThan(0,
            IntrusionLog::where('ip', '192.168.1.50')->where('category', 'rate')->count(),
            'the detector no longer detects anything');
    }

    /**
     * The point of the fix: the count belongs to a clock minute, so it retires
     * on its own whether or not the address goes quiet.
     */
    public function test_the_count_starts_again_in_the_next_minute(): void
    {
        $this->travelTo(now()->startOfHour());
        $this->hit('192.168.1.51', 8);
        $flagged = IntrusionLog::where('ip', '192.168.1.51')->count();
        $this->assertGreaterThan(0, $flagged);

        $this->travel(1)->minutes();
        $this->hit('192.168.1.51', 3);

        $this->assertSame($flagged, IntrusionLog::where('ip', '192.168.1.51')->count(),
            'the previous minute\'s count carried over, so a quiet minute still looks like a flood');
    }

    /**
     * The old key's lifetime was renewed by every request, so steady traffic
     * below the limit still accumulated without bound. Fifteen seconds apart
     * is exactly what the bell does.
     */
    public function test_steady_polite_traffic_never_trips_it(): void
    {
        $this->travelTo(now()->startOfHour());

        // Four requests a minute for half an hour: 120 requests, never more
        // than four in any one minute, against a limit of five.
        for ($i = 0; $i < 120; $i++) {
            $this->ids->inspect($this->requestFrom('192.168.1.52'));
            $this->travel(15)->seconds();
        }

        $this->assertSame(0, IntrusionLog::where('ip', '192.168.1.52')->count(),
            'an open browser tab is still enough to look like an attack');
        $this->assertFalse(BlockedIp::where('ip', '192.168.1.52')->exists(),
            'a real employee was auto-blocked for leaving the page open');
    }

    public function test_the_bells_own_polling_is_not_counted(): void
    {
        $user = $this->makeUser('system-admin');
        $this->actingAs($user);
        session(['otp_verified' => true]);
        SystemSetting::set('security.device_enforcement', false);

        for ($i = 0; $i < 20; $i++) {
            $this->get('/api/internal/security/alerts')->assertOk();
        }

        $this->assertSame(0, IntrusionLog::where('category', 'rate')->count(),
            'the system is still reporting itself');
    }

    /** Exempt from counting is not exempt from scanning. */
    public function test_the_exempt_route_is_still_scanned_for_signatures(): void
    {
        $user = $this->makeUser('system-admin');
        $this->actingAs($user);
        session(['otp_verified' => true]);
        SystemSetting::set('security.device_enforcement', false);

        $this->get('/api/internal/security/alerts?since=1%20UNION%20SELECT%20password%20FROM%20users');

        $this->assertGreaterThan(0, IntrusionLog::where('category', 'sqli')->count(),
            'the exemption turned the signature scanner off as well');
    }

    /** Nothing reachable from outside the LGU is on the exemption list. */
    public function test_only_the_internal_poll_is_exempt(): void
    {
        $this->assertSame(['api/internal/security/alerts'], config('security.rate_exempt_paths'));
    }

    /**
     * The exemption is matched in global middleware, before the router has
     * resolved anything — an ordinary page must not fall through it.
     */
    public function test_an_ordinary_page_is_not_exempt(): void
    {
        $this->hit('192.168.1.53', 8);

        $this->assertGreaterThan(0, IntrusionLog::where('ip', '192.168.1.53')->count(),
            'the exemption is swallowing traffic it was never meant to');
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }
}
