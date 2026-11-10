<?php

namespace Tests\Feature\Security;

use App\Models\IntrusionLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What `intrusion_logs.handled` means, and who is allowed to change it.
 *
 * It used to be cleared for every row the moment the Security Dashboard
 * rendered, so it recorded that somebody glanced at a page rather than that
 * anybody dealt with what was on it. Any work queue built on it was empty by
 * definition, and the topbar badge cleared itself on sight — which makes it a
 * notification, not an alert.
 *
 * Reviewing is an action now. The badge counts what is still outstanding.
 */
class IntrusionQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
    }

    private function admin(): User
    {
        return $this->makeUser('system-admin');
    }



    /**
     * `handled` was cleared for every row the moment the Security Dashboard
     * rendered, so it recorded that somebody glanced at a page — not that
     * anybody dealt with what was on it. Any queue built on that column was
     * empty by definition, and the topbar badge cleared itself on sight.
     */
    public function test_opening_the_dashboard_does_not_mark_events_as_dealt_with(): void
    {
        $this->actingAs($this->admin());
        session(['otp_verified' => true]);

        IntrusionLog::create([
            'category' => 'sqli', 'severity' => 'high', 'route' => 'login',
            'method' => 'GET', 'ip' => '192.168.1.87', 'handled' => false,
        ]);

        $this->get('/security')->assertOk();

        $this->assertSame(1, IntrusionLog::where('handled', false)->count(),
            'the dashboard cleared the queue just by being opened');
        $this->assertSame(1, $this->badgeCount(), 'the alert badge cleared itself on sight');
    }

    public function test_reviewing_is_an_action_and_it_clears_the_badge(): void
    {
        $this->actingAs($this->admin());
        session(['otp_verified' => true]);

        foreach (['sqli', 'xss', 'auth_fail'] as $category) {
            IntrusionLog::create([
                'category' => $category, 'severity' => 'high', 'route' => 'login',
                'method' => 'GET', 'ip' => '192.168.1.87', 'handled' => false,
            ]);
        }

        $one = IntrusionLog::first();
        $this->from('/security')->post('/security/intrusions/review', ['id' => $one->id])
            ->assertRedirect('/security');
        $this->assertTrue($one->fresh()->handled);
        $this->assertSame(2, $this->badgeCount());

        $this->from('/security')->post('/security/intrusions/review')->assertRedirect('/security');
        $this->assertSame(0, $this->badgeCount());
    }

    public function test_an_employee_cannot_clear_the_security_queue(): void
    {
        $this->actingAs($this->makeUser('employee'));
        session(['otp_verified' => true]);

        $event = IntrusionLog::create([
            'category' => 'sqli', 'severity' => 'high', 'route' => 'login',
            'method' => 'GET', 'ip' => '192.168.1.87', 'handled' => false,
        ]);

        $this->post('/security/intrusions/review')->assertForbidden();

        $this->assertFalse($event->fresh()->handled, 'an employee cleared a security event');

        // The refusal is itself recorded — PermissionMiddleware writes an
        // `rbac_denied` row — so the count goes up rather than staying at one.
        // That is the access-control case doing its job, and it lands in the
        // same queue as everything else waiting on review.
        $this->assertDatabaseHas('intrusion_logs', [
            'matched_rule' => 'rbac_denied',
            'route' => 'security/intrusions/review',
            'handled' => false,
        ]);
    }

    /** What the topbar bell would show right now. */
    private function badgeCount(): int
    {
        return (int) $this->getJson(route('web.security.alerts'))->assertOk()->json('unseen');
    }
}
