<?php

namespace Tests\Feature\Security;

use App\Models\SystemSetting;
use App\Models\User;
use App\Notifications\AccountLockoutAlertNotification;
use App\Notifications\IntrusionAlertNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Who hears about a detection, and when.
 *
 * The paper claims real-time intrusion alerts for three attacks. Two of them —
 * SQL injection and input manipulation — reach an administrator once an IP
 * crosses the auto-block threshold. The third, brute force, wrote a
 * high-severity row and told nobody: the lockout lives in LoginSecurityService
 * and the alert lived in a private method of the detector, on the other side of
 * the application. A detection that alerts and one that does not must differ by
 * which event fired, not by which class noticed it.
 */
class IntrusionAlertingTest extends TestCase
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

    // ------------------------------------------------------------ brute force

    public function test_a_brute_force_lockout_alerts_the_administrators(): void
    {
        $admin = $this->admin();
        Notification::fake();

        $victim = User::factory()->create(['email' => 'r.bautista@alicia.gov.ph']);

        for ($i = 0; $i < 3; $i++) {
            $this->post('/login', [
                'identifier' => $victim->email,
                'password' => 'not-the-password',
            ]);
        }

        $this->assertSame(User::STATUS_BLOCKED, $victim->fresh()->status,
            'the lockout itself did not fire, so this test proves nothing about the alert');

        Notification::assertSentTo($admin, AccountLockoutAlertNotification::class);
    }

    /**
     * Signed in, the administrator sees the topbar bell; signed out, they get
     * the email. Both, from one detection — which is the whole distinction the
     * user drew between an IDS catch and an access-control refusal.
     */
    public function test_the_lockout_alert_reaches_both_the_bell_and_the_inbox(): void
    {
        $admin = $this->admin();
        Notification::fake();

        $victim = User::factory()->create();
        for ($i = 0; $i < 3; $i++) {
            $this->post('/login', ['identifier' => $victim->email, 'password' => 'wrong']);
        }

        Notification::assertSentTo($admin, AccountLockoutAlertNotification::class,
            function ($notification, array $channels) {
                $this->assertContains('database', $channels, 'no in-app alert for a signed-in administrator');
                $this->assertContains('mail', $channels, 'no email for an administrator who is signed out');

                return true;
            });
    }

    public function test_an_employee_is_never_told_about_somebody_elses_lockout(): void
    {
        $this->admin();
        $employee = $this->makeUser('employee');
        Notification::fake();

        $victim = User::factory()->create();
        for ($i = 0; $i < 3; $i++) {
            $this->post('/login', ['identifier' => $victim->email, 'password' => 'wrong']);
        }

        Notification::assertNotSentTo($employee, AccountLockoutAlertNotification::class);
        Notification::assertNotSentTo($victim, AccountLockoutAlertNotification::class);
    }

    // ------------------------------------------------- sql injection / input

    public function test_an_auto_blocked_ip_still_alerts_the_administrators(): void
    {
        $admin = $this->admin();
        Notification::fake();

        SystemSetting::set('security.auto_block_threshold', '2');

        // Not 127.0.0.1: loopback is never auto-blocked, on purpose.
        for ($i = 0; $i < 2; $i++) {
            $this->call('GET', '/login', ['q' => "1' OR 1=1 --"], [], [], ['REMOTE_ADDR' => '192.168.4.11']);
        }

        Notification::assertSentTo($admin, IntrusionAlertNotification::class);
    }

    // ------------------------------------------------------------- the queue

    /**
     * QUEUE_CONNECTION defaults to `database` and no worker runs on the LAN box
     * this is deployed to, so a queued security alert is a row in `jobs` that
     * nobody ever reads — built, and behaving as though it were not. Leave
     * notifications may queue; these may not.
     */
    public function test_security_alerts_are_not_left_sitting_on_a_queue(): void
    {
        foreach ([IntrusionAlertNotification::class, AccountLockoutAlertNotification::class] as $class) {
            $this->assertNotInstanceOf(ShouldQueue::class, new $class(...$this->argsFor($class)),
                $class.' is queued, and nothing on this deployment runs a queue worker');
        }
    }

    private function argsFor(string $class): array
    {
        return $class === IntrusionAlertNotification::class
            ? ['192.168.1.1', 5]
            : ['someone@alicia.gov.ph', '192.168.1.1', 3];
    }
}
