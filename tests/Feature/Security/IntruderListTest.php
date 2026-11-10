<?php

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use App\Models\BlockedIp;
use App\Models\IntrusionLog;
use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Addresses seen attacking, with a button to block each one.
 *
 * The system has always known who attacked it — that is what intrusion_logs is
 * — but the only way to act on it was to read an address off the log and
 * retype it into a form.
 *
 * The hazard is the mirror image of that convenience. This runs on a municipal
 * LAN where DHCP reuses addresses and a whole office can sit behind one, so a
 * one-click block is one click from locking a department out of the leave
 * system. Most of what is tested here is the guard rails, not the button.
 */
class IntruderListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        SystemSetting::set('security.device_enforcement', false);
        SystemSetting::set('security.ids_enabled', false);
        $this->actingAs($this->makeUser('system-admin'));
        session(['otp_verified' => true]);
    }

    private function attack(string $ip, array $attributes = []): IntrusionLog
    {
        return IntrusionLog::create($attributes + [
            'category' => 'sqli', 'severity' => 'high', 'route' => 'employees',
            'method' => 'GET', 'payload_excerpt' => "' OR '1'='1",
            'matched_rule' => 'sqli_signature', 'ip' => $ip,
        ]);
    }

    // ------------------------------------------------------------- the listing

    public function test_an_attacker_is_listed_with_what_it_did(): void
    {
        $this->attack('203.0.113.9');
        $this->attack('203.0.113.9', ['category' => 'traversal', 'matched_rule' => 'traversal_signature']);

        $html = $this->get('/security/blocked-ips')->assertOk()->getContent();

        $this->assertStringContainsString('Seen attacking, not blocked', $html);
        $this->assertStringContainsString('203.0.113.9', $html);
        $this->assertStringContainsString('SQL injection', $html);
        $this->assertStringContainsString('Input manipulation', $html);
        $this->assertStringContainsString('High', $html, 'the severity grade is missing');
    }

    /**
     * A count on its own is what the broken rate counter punished us with:
     * five events looked damning and were the notification bell polling
     * itself. Reading them has to be one click away.
     */
    public function test_every_row_links_to_the_events_behind_it(): void
    {
        $this->attack('203.0.113.9');

        $html = $this->get('/security/blocked-ips')->assertOk()->getContent();

        $this->assertStringContainsString(
            route('security.intrusions', ['q' => '203.0.113.9']), $html,
            'there is no way to read the events before acting on them');
    }

    public function test_the_list_is_only_what_still_needs_a_decision(): void
    {
        $this->attack('203.0.113.9');
        $this->attack('198.51.100.4');
        BlockedIp::create([
            'ip' => '198.51.100.4', 'reason' => 'x', 'source' => 'auto',
            'expires_at' => now()->addDay(), 'active' => true,
        ]);

        $html = $this->get('/security/blocked-ips')->assertOk()->getContent();
        $panel = substr($html, strpos($html, 'Seen attacking'), 3000);

        $this->assertStringContainsString('203.0.113.9', $panel);
        $this->assertStringNotContainsString('198.51.100.4', $panel,
            'an address already blocked is still being offered for blocking');
    }

    /** A block that was lifted leaves the address needing a decision again. */
    public function test_a_lifted_block_puts_the_address_back_on_the_list(): void
    {
        $this->attack('203.0.113.9');
        BlockedIp::create([
            'ip' => '203.0.113.9', 'reason' => 'x', 'source' => 'auto',
            'expires_at' => now()->addDay(), 'active' => false,
        ]);

        $panel = $this->get('/security/blocked-ips')->assertOk()->getContent();

        $this->assertStringContainsString(route('security.block-intruder'), $panel);
    }

    public function test_an_address_that_cannot_be_blocked_is_not_offered(): void
    {
        $this->attack('127.0.0.1');

        $html = $this->get('/security/blocked-ips')->assertOk()->getContent();

        $this->assertStringContainsString('Nothing is waiting on a decision', $html,
            'loopback is being offered for blocking, and the block would do nothing');
    }

    /**
     * A stale browser tab must never put a colleague on a list headed
     * "seen attacking", one click from a ban.
     */
    public function test_only_the_three_attack_types_count(): void
    {
        foreach (['csrf', 'rate', 'privilege', 'device'] as $category) {
            $this->attack('192.168.1.50', ['category' => $category, 'matched_rule' => $category]);
        }

        $this->get('/security/blocked-ips')->assertOk()
            ->assertSee('Nothing is waiting on a decision');
    }

    // ------------------------------------------------------------- the warning

    public function test_an_address_inside_the_building_is_flagged(): void
    {
        $this->attack('192.168.1.31');

        $html = $this->get('/security/blocked-ips')->assertOk()->getContent();

        $this->assertStringContainsString('On your LAN', $html);
        $this->assertStringContainsString('office computer', $html);
        $this->assertStringContainsString('whoever uses it will be shut out', $html,
            'the confirmation does not say a colleague is about to lose access');
    }

    public function test_an_outside_address_gets_no_such_warning(): void
    {
        $this->attack('203.0.113.9');

        $html = $this->get('/security/blocked-ips')->assertOk()->getContent();

        $this->assertStringNotContainsString('On your LAN', $html);
    }

    // ------------------------------------------------------------- the blocking

    public function test_blocking_from_the_list_records_the_evidence_as_the_reason(): void
    {
        $this->attack('203.0.113.9');
        $this->attack('203.0.113.9');
        $this->attack('203.0.113.9', ['category' => 'traversal', 'matched_rule' => 'traversal_signature']);

        $this->post(route('security.block-intruder'), ['ip' => '203.0.113.9'])
            ->assertRedirect();

        $block = BlockedIp::where('ip', '203.0.113.9')->firstOrFail();

        $this->assertTrue($block->isInEffect());
        $this->assertSame('manual', $block->source, 'a person decided, so it is not automatic');
        $this->assertStringContainsString('3 intrusion events in 7 days', $block->reason);
        $this->assertStringContainsString('sql injection', $block->reason);
        $this->assertNotNull($block->expires_at, 'a one-click block should not be permanent');
        $this->assertNotNull(AuditLog::where('action', 'ip_blocked_from_evidence')->first());
    }

    /**
     * The address comes from the request, so none of it is taken on trust: the
     * reason is rebuilt from what is on record, and an address with nothing
     * against it is refused. Blocking on a report rather than on evidence is
     * what the manual form is for, and that asks for a reason to be typed.
     */
    public function test_an_address_with_no_evidence_cannot_be_blocked_this_way(): void
    {
        $this->attack('203.0.113.9');

        $this->post(route('security.block-intruder'), ['ip' => '198.51.100.77'])
            ->assertNotFound();

        $this->assertDatabaseMissing('blocked_ips', ['ip' => '198.51.100.77']);
    }

    public function test_a_trusted_address_is_refused_even_if_asked_for_directly(): void
    {
        $this->attack('127.0.0.1');

        $this->post(route('security.block-intruder'), ['ip' => '127.0.0.1'])
            ->assertForbidden();

        $this->assertDatabaseMissing('blocked_ips', ['ip' => '127.0.0.1']);
    }

    public function test_the_evidence_must_be_recent(): void
    {
        $this->attack('203.0.113.9')->forceFill(['created_at' => now()->subDays(30)])->save();

        $this->post(route('security.block-intruder'), ['ip' => '203.0.113.9'])
            ->assertNotFound();
    }

    public function test_blocking_from_the_list_asks_first(): void
    {
        $this->attack('203.0.113.9');

        $html = $this->get('/security/blocked-ips')->assertOk()->getContent();

        preg_match('#<form[^>]*blocked-ips/intruder[^>]*>#s', $html, $m);
        $this->assertNotEmpty($m);
        $this->assertStringContainsString('data-confirm', $m[0]);
    }

    /** Blocking by hand shuts somebody out too, so it asks as well. */
    public function test_the_manual_block_form_asks_first(): void
    {
        $html = $this->get('/security/blocked-ips')->assertOk()->getContent();

        preg_match('#<form method="POST"[^>]*security/blocked-ips"[^>]*>#s', $html, $m);
        $this->assertNotEmpty($m, 'the manual block form is gone');
        $this->assertStringContainsString('data-confirm', $m[0],
            'blocking an address by hand goes through on the click');
    }

    /** Re-blocking rewrote the label but kept a reason that contradicted it. */
    public function test_blocking_again_rewrites_the_reason_to_match(): void
    {
        $admin = $this->makeUser('system-admin');
        $admin->update(['name' => 'Noly J. Macarubbo']);
        $this->actingAs($admin);
        session(['otp_verified' => true]);

        $block = BlockedIp::create([
            'ip' => '192.168.1.7',
            'reason' => 'Automatic block: 5 intrusion events in 10 minutes',
            'source' => 'auto', 'expires_at' => now()->subDay(), 'active' => false,
        ]);

        $this->post(route('security.reblock-ip', $block))->assertRedirect();

        $reason = $block->refresh()->reason;

        $this->assertStringContainsString('Blocked again by Noly J. Macarubbo', $reason);
        $this->assertStringContainsString('originally', $reason,
            'the original reason was lost, so why the address was blocked is gone');
        $this->assertStringStartsNotWith('Automatic block', $reason,
            'the row still opens as an automatic block while labelled manual');
    }

    /** Re-blocking twice must not nest the sentence inside itself. */
    public function test_blocking_again_twice_does_not_stack_the_reason(): void
    {
        $block = BlockedIp::create([
            'ip' => '192.168.1.7', 'reason' => 'Automatic block: 5 events',
            'source' => 'auto', 'expires_at' => now()->subDay(), 'active' => false,
        ]);

        $this->post(route('security.reblock-ip', $block))->assertRedirect();
        $block->refresh()->update(['active' => false]);
        $this->post(route('security.reblock-ip', $block))->assertRedirect();

        $this->assertSame(1, substr_count($block->refresh()->reason, 'Blocked again by'));
    }
}
