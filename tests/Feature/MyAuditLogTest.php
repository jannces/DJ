<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Everybody the system audits can read their own trail, and only their own.
 *
 * The point of the page is the claim the system makes about itself: that every
 * change is recorded against the person who made it. That claim is worth more
 * when the person can see the record. It would be worth nothing if the page
 * also showed them somebody else's.
 */
class MyAuditLogTest extends TestCase
{
    use RefreshDatabase;

    private function entryFor(User $user, string $action): AuditLog
    {
        return AuditLog::create([
            'user_id' => $user->id,
            'role_snapshot' => 'employee',
            'action' => $action,
            'ip' => '127.0.0.1',
        ]);
    }

    /** Every role on the payroll reaches it. */
    public function test_each_audited_role_can_open_their_own_trail(): void
    {
        $this->seedCore();

        foreach (['employee', 'hr', 'department-head', 'mayor'] as $role) {
            $user = $this->makeUser($role);
            $this->actingAs($user);
            session(['otp_verified' => true]);

            $this->get(route('audit.mine'))->assertOk()->assertSee('My Audit Log');
        }
    }

    /** Their own entries are there. */
    public function test_their_own_entries_are_shown(): void
    {
        $this->seedCore();
        $user = $this->makeUser('employee');
        $this->entryFor($user, 'leave_requested');

        $this->actingAs($user);
        session(['otp_verified' => true]);

        $this->get(route('audit.mine'))->assertOk()->assertSee('Leave requested');
    }

    /**
     * And nobody else's, which is the whole reason this is a separate page.
     */
    public function test_another_persons_entries_are_not_shown(): void
    {
        $this->seedCore();
        $me = $this->makeUser('employee');
        $someone = $this->makeUser('hr');

        $this->entryFor($someone, 'user_blocked');

        $this->actingAs($me);
        session(['otp_verified' => true]);

        $this->get(route('audit.mine'))
            ->assertOk()
            ->assertDontSee('User blocked')
            ->assertSee('Nothing recorded for you yet.');
    }

    /**
     * The scope cannot be steered from the request.
     *
     * A page that reads an id from the query string is a page that can be
     * asked for another person's trail by editing the address bar. The only
     * thing a request may influence here is which of the holder's OWN rows
     * appear, so these are all ignored.
     */
    public function test_the_scope_cannot_be_changed_through_the_request(): void
    {
        $this->seedCore();
        $me = $this->makeUser('employee');
        $someone = $this->makeUser('hr');

        $this->entryFor($someone, 'user_blocked');

        $this->actingAs($me);
        session(['otp_verified' => true]);

        foreach ([
            '?user_id='.$someone->id,
            '?user='.$someone->id,
            '?id='.$someone->id,
            '?q='.$someone->name,
        ] as $query) {
            $this->get(route('audit.mine').$query)
                ->assertOk()
                ->assertDontSee('User blocked');
        }
    }

    /**
     * The action filter offers only what this person has done.
     *
     * Building it from the whole table would leak the shape of everybody
     * else's activity through a dropdown. Small, and unnecessary.
     */
    public function test_the_filter_does_not_list_other_peoples_actions(): void
    {
        $this->seedCore();
        $me = $this->makeUser('employee');
        $someone = $this->makeUser('system-admin');

        $this->entryFor($me, 'leave_requested');
        $this->entryFor($someone, 'user_blocked');

        $this->actingAs($me);
        session(['otp_verified' => true]);

        $html = $this->get(route('audit.mine'))->assertOk()->getContent();

        $this->assertStringContainsString('leave_requested', $html);
        $this->assertStringNotContainsString('user_blocked', $html,
            "the filter lists an action only somebody else has performed");
    }

    /** A guest gets the sign-in page, not a trail. */
    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->seedCore();

        $this->get(route('audit.mine'))->assertRedirect(route('login'));
    }

    /**
     * Reading your own trail is not reading the log.
     *
     * audit.view-own must not open the administrator's page, or the split
     * between the two would be cosmetic.
     */
    public function test_own_trail_does_not_open_the_whole_log(): void
    {
        $this->seedCore();
        $this->actingAs($this->makeUser('employee'));
        session(['otp_verified' => true]);

        $this->get(route('audit.index'))->assertForbidden();
    }
}
