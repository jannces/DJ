<?php

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use App\Models\BlockedIp;
use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Blocked IP addresses.
 *
 * The page was a form holding the left third and a list squeezed into what was
 * left, and the list could only ever lift a block — once lifted, the row had no
 * action at all, so blocking the same address again meant reading it off the
 * row and retyping it into the form.
 *
 * The two things an administrator needs are both here now, and they are the
 * two the request named: let someone back in who should never have been shut
 * out, and shut an address out again.
 */
class BlockedIpListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        SystemSetting::set('security.device_enforcement', false);
        $this->actingAs($this->makeUser('system-admin'));
        session(['otp_verified' => true]);
    }

    private function block(array $attributes = []): BlockedIp
    {
        return BlockedIp::create($attributes + [
            'ip' => '203.0.113.9',
            'reason' => 'Probing for /etc/passwd',
            'source' => 'auto',
            'expires_at' => now()->addDay(),
            'active' => true,
        ]);
    }

    // ------------------------------------------------------------- the layout

    public function test_the_list_has_the_page_and_the_form_is_behind_a_button(): void
    {
        $this->block();

        $html = $this->get('/security/blocked-ips')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '#<div class="list-actions">\s*<a [^>]*>\s*<i class="bi bi-slash-circle"></i>Block an IP#',
            $html, 'the block form is not behind a button above the container');
        $this->assertMatchesRegularExpression(
            '#<div class="card-header"><span>Blocks</span></div>\s*<form [^>]*class="list-toolbar"#', $html);
        $this->assertStringContainsString('<div data-list>', $html);
    }

    // --------------------------------------------------- the two things asked

    public function test_a_block_in_effect_offers_only_a_green_lift(): void
    {
        $block = $this->block();

        $html = $this->get('/security/blocked-ips')->assertOk()->getContent();

        $this->assertStringContainsString('Blocked', $html);
        $this->assertMatchesRegularExpression('#btn btn-sm btn-success#', $html,
            'lifting a block is not a green button');
        $this->assertStringContainsString(route('security.unblock-ip', $block), $html);
        $this->assertStringNotContainsString(route('security.reblock-ip', $block), $html,
            'a block in effect is offering to be applied again');
    }

    public function test_a_lifted_block_offers_only_a_red_block_again(): void
    {
        $block = $this->block(['active' => false]);

        $html = $this->get('/security/blocked-ips?show=lifted')->assertOk()->getContent();

        $this->assertStringContainsString('Lifted', $html);
        $this->assertMatchesRegularExpression('#btn btn-sm btn-danger#', $html,
            'blocking again is not a red button');
        $this->assertStringContainsString(route('security.reblock-ip', $block), $html);
        $this->assertStringNotContainsString(route('security.unblock-ip', $block), $html,
            'a lifted block is offering to be lifted again');
    }

    /** The case the request named: the system shut out a legitimate user. */
    public function test_an_automatic_block_can_be_lifted(): void
    {
        $block = $this->block([
            'ip' => '192.168.1.30',
            'reason' => 'Automatic block: 5 intrusion events in 10 minutes',
        ]);

        $this->post(route('security.unblock-ip', $block))->assertRedirect();

        $this->assertFalse($block->refresh()->isInEffect());
        $this->assertDatabaseHas('audit_logs', ['action' => 'ip_unblocked']);
    }

    public function test_a_lifted_block_can_be_put_back(): void
    {
        $block = $this->block(['active' => false, 'expires_at' => now()->subDay()]);

        $this->post(route('security.reblock-ip', $block))->assertRedirect();

        $block->refresh();
        $this->assertTrue($block->isInEffect(), 'the address is still being let through');
        $this->assertTrue($block->expires_at->isFuture(), 'it was put back already expired');
    }

    /** Re-blocking is a person's decision, and is recorded as one. */
    public function test_blocking_again_is_recorded_against_whoever_did_it(): void
    {
        $admin = $this->makeUser('system-admin');
        $this->actingAs($admin);
        session(['otp_verified' => true]);
        $block = $this->block(['active' => false]);

        $this->post(route('security.reblock-ip', $block))->assertRedirect();

        $this->assertSame('manual', $block->refresh()->source,
            'it still reads as an automatic block the system made');
        $this->assertSame($admin->id, $block->blocked_by);
        $this->assertNotNull(AuditLog::where('action', 'ip_blocked_again')->first());
    }

    // ----------------------------------------------------- one definition now

    /**
     * The badge said Lifted for an expired block while the button next to it
     * counted the same row as active and offered to unblock it.
     */
    public function test_an_expired_block_reads_as_lifted_everywhere(): void
    {
        $block = $this->block(['active' => true, 'expires_at' => now()->subHour()]);

        $this->assertFalse($block->isInEffect());

        $html = $this->get('/security/blocked-ips?show=lifted')->assertOk()->getContent();

        $this->assertStringContainsString(route('security.reblock-ip', $block), $html);
        $this->assertStringNotContainsString(route('security.unblock-ip', $block), $html,
            'a row reading Lifted is still offering to lift it');
    }

    public function test_a_permanent_block_is_in_effect_and_says_so(): void
    {
        $this->block(['expires_at' => null]);

        $html = $this->get('/security/blocked-ips')->assertOk()->getContent();

        $this->assertStringContainsString('Permanent', $html);
    }

    // ------------------------------------------------------------- the filters

    public function test_the_list_shows_what_is_in_effect_by_default(): void
    {
        $this->block(['ip' => '203.0.113.9']);
        $this->block(['ip' => '198.51.100.4', 'active' => false]);

        $this->get('/security/blocked-ips')->assertOk()
            ->assertSee('203.0.113.9')->assertDontSee('198.51.100.4');
        $this->get('/security/blocked-ips?show=lifted')->assertOk()
            ->assertSee('198.51.100.4')->assertDontSee('203.0.113.9');
        $this->get('/security/blocked-ips?show=all')->assertOk()
            ->assertSee('198.51.100.4')->assertSee('203.0.113.9');
    }

    public function test_blocks_can_be_narrowed_by_who_made_them(): void
    {
        $this->block(['ip' => '203.0.113.9', 'source' => 'auto']);
        $this->block(['ip' => '198.51.100.4', 'source' => 'manual']);

        $this->get('/security/blocked-ips?source=manual')->assertOk()
            ->assertSee('198.51.100.4')->assertDontSee('203.0.113.9');
    }

    public function test_blocks_can_be_searched_by_address_or_reason(): void
    {
        $this->block(['ip' => '203.0.113.9', 'reason' => 'Probing for /etc/passwd']);
        $this->block(['ip' => '198.51.100.4', 'reason' => 'Repeated injection attempts']);

        $this->get('/security/blocked-ips?q=injection')->assertOk()
            ->assertSee('198.51.100.4')->assertDontSee('203.0.113.9');
        $this->get('/security/blocked-ips?q=203.0')->assertOk()
            ->assertSee('203.0.113.9')->assertDontSee('198.51.100.4');
    }

    public function test_blocking_by_hand_still_works(): void
    {
        $this->post('/security/blocked-ips', [
            'ip' => '198.51.100.7',
            'reason' => 'Scanning the login page',
            'hours' => 6,
        ])->assertRedirect();

        $this->assertDatabaseHas('blocked_ips', ['ip' => '198.51.100.7', 'source' => 'manual']);
    }

    /** A rejected block reopens the panel with what was typed. */
    public function test_a_rejected_block_comes_back_visible(): void
    {
        $html = $this->from('/security/blocked-ips')
            ->followingRedirects()
            ->post('/security/blocked-ips', ['ip' => 'not-an-address', 'reason' => 'Scanning'])
            ->assertOk()->getContent();

        preg_match('#<div class="modal fade" id="block-new"[^>]*>#s', $html, $m);
        $this->assertStringContainsString('data-open-on-load', $m[0]);
        $this->assertStringContainsString('is-invalid', $html);
        $this->assertStringContainsString('Scanning', $html, 'the typed reason was thrown away');
    }
}
