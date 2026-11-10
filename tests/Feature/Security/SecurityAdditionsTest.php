<?php

namespace Tests\Feature\Security;

use App\Models\FailedLogin;
use App\Models\IntrusionLog;
use App\Models\Role;
use App\Models\User;
use App\Services\Security\AuditLogger;
use App\Services\Security\SecurityDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The three cards added to the Security Dashboard.
 *
 * Each turns a number the screen already showed into something an
 * administrator can act on. "Six unreviewed" is a backlog you can start; "23 of
 * 32 failures against usernames that do not exist" is a diagnosis rather than a
 * count; and a system whose case rests on auditability should show its own
 * privilege changes to the person making them.
 */
class SecurityAdditionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
    }

    private function service(): SecurityDashboardService
    {
        return app(SecurityDashboardService::class);
    }

    private function event(array $attributes = []): IntrusionLog
    {
        return IntrusionLog::create($attributes + [
            'category' => 'sqli', 'severity' => 'high', 'route' => 'login',
            'method' => 'GET', 'ip' => '192.168.1.87', 'handled' => false,
        ]);
    }

    // ------------------------------------------------------ unreviewed events

    /**
     * The queue speaks the paper's vocabulary in bold and keeps the stored
     * category underneath. A summary is read to learn which of the three; a
     * queue is read by somebody about to act, and "traversal on
     * /files/download" tells them what was attempted in a way "input
     * manipulation" cannot.
     */
    public function test_the_queue_names_the_attack_and_keeps_the_stored_category(): void
    {
        $this->event(['category' => 'traversal', 'route' => 'files/download']);

        $row = $this->service()->unreviewed()['rows'][0];

        $this->assertSame('Input manipulation', $row['label']);
        $this->assertSame('traversal · /files/download', $row['detail']);
    }

    public function test_a_lockout_reads_as_brute_force(): void
    {
        $this->event(['category' => 'auth_fail', 'matched_rule' => 'lockout_threshold']);

        $this->assertSame('Brute force', $this->service()->unreviewed()['rows'][0]['label']);
    }

    /**
     * An event outside the paper's three — a refused permission, say — is still
     * work waiting on somebody, so it stays in the queue under its own name
     * rather than being dropped or mislabelled as one of the three.
     */
    public function test_an_event_outside_the_three_is_still_queued(): void
    {
        $this->event(['category' => 'privilege', 'severity' => 'medium', 'matched_rule' => 'rbac_denied']);

        $row = $this->service()->unreviewed()['rows'][0];

        $this->assertSame('Privilege', $row['label']);
        $this->assertSame(1, $this->service()->unreviewed()['total']);
    }

    public function test_a_reviewed_event_leaves_the_queue(): void
    {
        $this->event();
        $this->event(['handled' => true]);

        $this->assertSame(1, $this->service()->unreviewed()['total']);
    }

    // ------------------------------------------------------ failures by reason

    public function test_the_failure_reasons_are_named_in_words_and_ranked(): void
    {
        foreach ([['unknown_user', 3], ['invalid_password', 1]] as [$reason, $times]) {
            for ($i = 0; $i < $times; $i++) {
                FailedLogin::create([
                    'identifier' => 'someone', 'ip' => '192.168.1.42',
                    'reason' => $reason, 'occurred_at' => now()->subHours($i + 1),
                ]);
            }
        }

        $rows = collect($this->service()->failuresByReason());

        $this->assertSame('Unknown username', $rows->first()['label'], 'the diagnosis sorts first');
        $this->assertSame(3, $rows->first()['value']);
        $this->assertSame(1, $rows->firstWhere('label', 'Wrong password')['value']);

        // Every reason the service records has a row, so a zero reads as "none"
        // rather than vanishing from a chart that claims to be complete.
        $this->assertSame(0, $rows->firstWhere('label', 'Account blocked')['value']);
    }

    /** A reason the service starts recording later must not disappear. */
    public function test_an_unmapped_reason_still_gets_a_row(): void
    {
        FailedLogin::create([
            'identifier' => 'someone', 'ip' => '192.168.1.42',
            'reason' => 'device_not_authorized', 'occurred_at' => now()->subHour(),
        ]);

        $rows = collect($this->service()->failuresByReason());

        $this->assertSame(1, $rows->firstWhere('label', 'Device not authorized')['value']);
    }

    public function test_old_failures_fall_out_of_the_window(): void
    {
        FailedLogin::create([
            'identifier' => 'someone', 'ip' => '192.168.1.42',
            'reason' => 'unknown_user', 'occurred_at' => now()->subDays(30),
        ]);

        $this->assertSame(0, collect($this->service()->failuresByReason())->sum('value'));
    }

    // -------------------------------------------------------- privilege changes

    public function test_privilege_changes_report_who_changed_what(): void
    {
        $admin = $this->makeUser('system-admin');
        $target = User::factory()->create(['name' => 'Bautista, Rosa']);

        $this->actingAs($admin);
        app(AuditLogger::class)->log('user_access_changed', $target, [], ['name' => $target->name]);
        // Not a privilege change: it must not appear here.
        app(AuditLogger::class)->log('login', $target, [], []);

        $rows = $this->service()->privilegeChanges();

        $this->assertCount(1, $rows, 'only role, permission and account changes belong here');
        $this->assertSame('Changed roles for', $rows[0]['what']);
        $this->assertSame('Bautista, Rosa', $rows[0]['target']);
        $this->assertSame($admin->name, $rows[0]['who']);
    }

    public function test_a_role_edit_is_a_privilege_change(): void
    {
        $this->actingAs($this->makeUser('system-admin'));
        $role = Role::where('slug', 'employee')->firstOrFail();

        app(AuditLogger::class)->log('role_updated', $role, [], ['name' => $role->name]);

        $this->assertSame('Changed permissions on role', $this->service()->privilegeChanges()[0]['what']);
    }

    // --------------------------------------------------------------- on screen

    public function test_all_three_cards_render(): void
    {
        $this->actingAs($this->makeUser('system-admin'));
        session(['otp_verified' => true]);

        $this->get('/security')->assertOk()
            ->assertSee('Unreviewed events')
            ->assertSee('Failed sign-ins by reason')
            ->assertSee('Privilege changes');
    }
}
