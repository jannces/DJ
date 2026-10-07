<?php

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use App\Models\BlockedIp;
use App\Models\IntrusionLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Clearing out the records the broken rate counter left behind.
 *
 * The risk in a command like this is that it takes more than it should.
 * Removing entries from a security log is only defensible against criteria
 * that can be stated, so what it must not touch matters more here than what
 * it does.
 */
class PurgeRateFalsePositivesTest extends TestCase
{
    use RefreshDatabase;

    private const POLL = 'api/internal/security/alerts';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
    }

    private function event(array $attributes = []): IntrusionLog
    {
        return IntrusionLog::create($attributes + [
            'category' => 'rate',
            'severity' => 'medium',
            'route' => self::POLL,
            'method' => 'GET',
            'payload_excerpt' => 'request rate exceeded',
            'matched_rule' => 'rate_signature',
            'ip' => '127.0.0.1',
        ]);
    }

    public function test_it_removes_the_polling_events(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->event();
        }

        $this->artisan('security:purge-rate-false-positives --force')
            ->assertSuccessful();

        $this->assertSame(0, IntrusionLog::count());
    }

    /** Everything that is not a rate event on that endpoint stays. */
    public function test_it_leaves_every_real_finding_alone(): void
    {
        $this->event();
        $sqli = $this->event(['category' => 'sqli', 'severity' => 'high', 'route' => 'employees']);
        $xss = $this->event(['category' => 'xss', 'severity' => 'high', 'route' => 'leave']);
        $priv = $this->event(['category' => 'privilege', 'route' => 'users']);
        $device = $this->event(['category' => 'device', 'severity' => 'high', 'route' => 'dashboard']);
        // A rate event somewhere other than the polling endpoint: it may be an
        // artefact, but it cannot be proved one, so the default leaves it.
        $elsewhere = $this->event(['route' => 'dashboard', 'ip' => '192.168.1.9']);

        $this->artisan('security:purge-rate-false-positives --force')->assertSuccessful();

        foreach ([$sqli, $xss, $priv, $device, $elsewhere] as $kept) {
            $this->assertDatabaseHas('intrusion_logs', ['id' => $kept->id]);
        }
        $this->assertSame(5, IntrusionLog::count());
    }

    public function test_the_wider_sweep_is_opt_in(): void
    {
        $this->event(['route' => 'dashboard', 'ip' => '192.168.1.9']);

        $this->artisan('security:purge-rate-false-positives --all-rate --force')
            ->assertSuccessful();

        $this->assertSame(0, IntrusionLog::count());
    }

    /** Even opted in, the wider sweep only takes rate events. */
    public function test_the_wider_sweep_still_only_takes_rate_events(): void
    {
        $sqli = $this->event(['category' => 'sqli', 'severity' => 'high', 'route' => 'dashboard']);
        $this->event(['route' => 'dashboard']);

        $this->artisan('security:purge-rate-false-positives --all-rate --force')->assertSuccessful();

        $this->assertSame(1, IntrusionLog::count());
        $this->assertDatabaseHas('intrusion_logs', ['id' => $sqli->id]);
    }

    /** Without --force it asks, and no is a real answer. */
    public function test_it_asks_first(): void
    {
        $this->event();

        $this->artisan('security:purge-rate-false-positives')
            ->expectsConfirmation('Remove these records permanently?', 'no')
            ->assertSuccessful();

        $this->assertSame(1, IntrusionLog::count());
    }

    /** Taking records out of a security log is itself a security event. */
    public function test_the_removal_is_audited(): void
    {
        $this->event();
        $this->event();

        $this->artisan('security:purge-rate-false-positives --force')->assertSuccessful();

        $entry = AuditLog::where('action', 'intrusion_false_positives_purged')->firstOrFail();
        $this->assertSame(2, $entry->new_values['polling_endpoint_events']);
        $this->assertArrayHasKey('reason', $entry->new_values);
    }

    /**
     * The bug did not only write records. Five events in ten minutes issued a
     * 24-hour block, so an employee could be locked out for keeping the page
     * open. Once the events are gone, an address with nothing left against it
     * has nothing to be blocked for.
     */
    public function test_it_lifts_a_block_left_with_nothing_behind_it(): void
    {
        $this->event(['ip' => '192.168.1.30']);
        BlockedIp::create([
            'ip' => '192.168.1.30',
            'reason' => 'Automatic block: 6 intrusion events in 10 minutes',
            'source' => 'auto',
            'expires_at' => now()->addDay(),
            'active' => true,
        ]);

        $this->artisan('security:purge-rate-false-positives --unblock --force')
            ->assertSuccessful();

        $this->assertDatabaseHas('blocked_ips', ['ip' => '192.168.1.30', 'active' => false]);
    }

    /** An address with a real finding against it keeps its block. */
    public function test_it_keeps_a_block_that_is_still_earned(): void
    {
        $this->event(['ip' => '192.168.1.31']);
        $this->event(['ip' => '192.168.1.31', 'category' => 'sqli', 'severity' => 'high', 'route' => 'employees']);
        BlockedIp::create([
            'ip' => '192.168.1.31',
            'reason' => 'Automatic block: 6 intrusion events in 10 minutes',
            'source' => 'auto',
            'expires_at' => now()->addDay(),
            'active' => true,
        ]);

        $this->artisan('security:purge-rate-false-positives --unblock --force')
            ->assertSuccessful();

        $this->assertDatabaseHas('blocked_ips', ['ip' => '192.168.1.31', 'active' => true]);
    }

    /** A block an administrator placed by hand is never touched. */
    public function test_it_never_lifts_a_manual_block(): void
    {
        BlockedIp::create([
            'ip' => '203.0.113.7',
            'reason' => 'Placed by the administrator',
            'source' => 'manual',
            'expires_at' => now()->addDay(),
            'active' => true,
        ]);

        $this->artisan('security:purge-rate-false-positives --unblock --force')->assertSuccessful();

        $this->assertDatabaseHas('blocked_ips', ['ip' => '203.0.113.7', 'active' => true]);
    }

    public function test_it_says_so_when_there_is_nothing_to_do(): void
    {
        $this->artisan('security:purge-rate-false-positives --force')
            ->expectsOutputToContain('Nothing to remove.')
            ->assertSuccessful();
    }
}
