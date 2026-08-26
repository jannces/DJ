<?php

namespace App\Services\Security;

use App\Models\User;
use App\Notifications\AccountLockoutAlertNotification;
use App\Notifications\IntrusionAlertNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * The one place that decides who hears about a security event.
 *
 * The audience was previously a query buried in a private method of the
 * detector, which is why the brute-force path could not reach it: the account
 * lockout lives in LoginSecurityService, on the other side of the application.
 * A detection that alerts and a detection that does not should differ by which
 * event fired, never by which class happened to notice it.
 */
class SecurityAlerter
{
    /** Roles that carry responsibility for the system's security. */
    public const ADMIN_ROLES = ['super-admin', 'system-admin'];

    /** An IP crossed the auto-block threshold — SQL injection, XSS, traversal. */
    public function ipAutoBlocked(string $ip, int $events): void
    {
        $this->alert(new IntrusionAlertNotification($ip, $events));
    }

    /** An account crossed the failed-attempt threshold — brute force. */
    public function accountLocked(string $account, string $ip, int $attempts, ?string $until = null): void
    {
        $this->alert(new AccountLockoutAlertNotification($account, $ip, $attempts, $until));
    }

    /**
     * One administrator at a time, each in its own try.
     *
     * `Notification::send()` on a collection walks notifiables in order, so a
     * mail server that is down or misconfigured — the ordinary state of an
     * offline LAN deployment — throws on the first administrator and the rest
     * never hear anything at all. Sending them one by one contains that to the
     * administrator it happened to.
     *
     * Within one administrator the channels run in the order `via()` lists
     * them, and `database` is first, so the in-app alert is already written
     * before the mail attempt can fail.
     *
     * Nothing here may propagate: this runs inside a sign-in and inside the
     * IDS middleware, and an unreachable SMTP host must not turn a blocked
     * attack into a 500.
     */
    private function alert(Notification $notification): void
    {
        $admins = User::whereHas('roles', fn ($q) => $q->whereIn('slug', self::ADMIN_ROLES))->get();

        foreach ($admins as $admin) {
            try {
                $admin->notify($notification);
            } catch (\Throwable $e) {
                Log::error('Security alert could not be delivered.', [
                    'notification' => $notification::class,
                    'user_id' => $admin->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
