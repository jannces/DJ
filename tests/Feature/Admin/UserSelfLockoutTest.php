<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An administrator cannot lock themselves out of the system.
 *
 * Deactivate, Block and Archive all end the same way: the person who pressed
 * the button cannot sign in again. On an offline LAN system with no
 * password-reset email and, in this LGU, one system administrator, that is not
 * an inconvenience -- it is the end of administrative access, recoverable only
 * by editing the database by hand.
 *
 * Every refusal is tested at the ROUTE, not in the view. The buttons are greyed
 * out on your own row, but a hidden control is not a control: these are plain
 * POSTs that anyone holding the permission can send, and the earlier version of
 * this page accepted all three.
 */
class UserSelfLockoutTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seedCore();
        $admin = $this->makeUser('system-admin');
        $this->actingAs($admin);
        session(['otp_verified' => true]);

        return $admin;
    }

    public function test_an_administrator_cannot_deactivate_their_own_account(): void
    {
        $admin = $this->admin();

        $this->post(route('users.toggle-active', $admin))->assertRedirect();

        $this->assertSame(User::STATUS_ACTIVE, $admin->fresh()->status,
            'the administrator deactivated themselves and can no longer sign in');
    }

    public function test_an_administrator_cannot_block_their_own_account(): void
    {
        $admin = $this->admin();

        $this->post(route('users.block', $admin), ['reason' => 'testing'])->assertRedirect();

        $this->assertSame(User::STATUS_ACTIVE, $admin->fresh()->status,
            'the administrator blocked themselves');
    }

    public function test_an_administrator_cannot_archive_their_own_account(): void
    {
        $admin = $this->admin();

        $this->post(route('users.archive', $admin))->assertRedirect();

        $this->assertFalse($admin->fresh()->trashed(),
            'the administrator soft-deleted their own account');
    }

    /** And is told why, rather than the page quietly doing nothing. */
    public function test_the_refusal_says_what_happened(): void
    {
        $admin = $this->admin();

        $this->post(route('users.toggle-active', $admin))
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'your own account'));
    }

    /**
     * The guard is about YOUR account, not about administrators.
     *
     * A guard that refused every system-admin would make the role
     * unmanageable -- there would be no way to retire one who had left.
     */
    public function test_another_administrator_can_still_be_deactivated(): void
    {
        $this->admin();
        $colleague = $this->makeUser('system-admin');

        $this->post(route('users.toggle-active', $colleague))->assertRedirect();

        $this->assertSame(User::STATUS_INACTIVE, $colleague->fresh()->status,
            'the guard is stopping actions on other people too');
    }

    public function test_another_account_can_still_be_archived(): void
    {
        $this->admin();
        $employee = $this->makeUser('employee');

        $this->post(route('users.archive', $employee))->assertRedirect();

        $this->assertTrue($employee->fresh()->trashed());
    }

    /**
     * Resetting your OWN password is still allowed.
     *
     * It is recoverable -- you sign in with the first-time password and set a
     * new one -- so it is a mistake, not a lockout, and refusing it would be
     * the guard spreading past its reason.
     */
    public function test_resetting_your_own_password_is_not_refused(): void
    {
        $admin = $this->admin();

        $this->post(route('users.reset-password', $admin))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertTrue($admin->fresh()->must_change_password);
    }

    /** The list greys the three out on your own row, and leaves them on others. */
    public function test_the_list_disables_the_three_controls_on_your_own_row(): void
    {
        $admin = $this->admin();
        $colleague = $this->makeUser('employee');

        $html = $this->get(route('users.index'))->assertOk()->getContent();

        foreach (['Block', 'Deactivate', 'Archive'] as $label) {
            $this->assertStringContainsString(
                '<span class="dropdown-item disabled">'.$label.'</span>', $html,
                "{$label} is not greyed out on the signed-in row");
        }

        // The other person's row still offers all three as real controls.
        foreach (['toggle-active', 'block', 'archive'] as $action) {
            $this->assertStringContainsString(
                route('users.'.$action, $colleague), $html,
                "the {$action} control went missing from everyone else's row");
        }

        $this->assertStringNotContainsString(route('users.archive', $admin), $html,
            'the signed-in row still posts an archive form');
    }
}
