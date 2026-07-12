<?php

namespace App\Services\Auth;

use App\Models\FailedLogin;
use App\Models\IntrusionLog;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Security\AuditLogger;
use Illuminate\Http\Request;

/**
 * Brute-force protection (FR-A8): after N failed attempts (default 3) the
 * account is blocked for a configurable period (default 24 h) with the
 * reason, IP, browser and timestamp preserved. Admins may unblock manually.
 */
class LoginSecurityService
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function maxAttempts(): int
    {
        return (int) SystemSetting::get('auth.lockout_attempts', 3);
    }

    public function recordFailure(Request $request, string $identifier, ?User $user, string $reason): void
    {
        FailedLogin::create([
            'identifier' => $identifier,
            'user_id' => $user?->id,
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'reason' => $reason,
            'occurred_at' => now(),
        ]);

        if (! $user) {
            return;
        }

        $user->increment('failed_attempts');
        if ($user->failed_attempts >= $this->maxAttempts()) {
            $this->blockAccount($user, $request);
        }
    }

    public function recordSuccess(Request $request, User $user): void
    {
        $user->forceFill([
            'failed_attempts' => 0,
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        $this->audit->log('login', $user, [], ['ip' => $request->ip()], $user);
    }

    public function blockAccount(User $user, Request $request): void
    {
        $hours = (int) SystemSetting::get('auth.lockout_hours', 24);
        $reason = sprintf('Exceeded %d failed login attempts', $this->maxAttempts());

        $user->forceFill([
            'status' => User::STATUS_BLOCKED,
            'blocked_until' => now()->addHours($hours),
            'blocked_reason' => $reason,
        ])->save();

        IntrusionLog::create([
            'category' => 'auth_fail',
            'severity' => 'high',
            'route' => 'login',
            'method' => 'POST',
            'payload_excerpt' => 'Account auto-blocked: '.$user->email,
            'matched_rule' => 'lockout_threshold',
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'user_id' => $user->id,
        ]);

        $this->audit->log('account_blocked', $user, [], [
            'reason' => $reason,
            'blocked_until' => (string) $user->blocked_until,
            'ip' => $request->ip(),
            'browser' => (string) $request->userAgent(),
        ], $user);
    }

    public function unblockAccount(User $user, ?User $admin = null, string $how = 'manual'): void
    {
        $old = ['status' => $user->status, 'blocked_until' => (string) $user->blocked_until];
        $user->forceFill([
            'status' => User::STATUS_ACTIVE,
            'blocked_until' => null,
            'blocked_reason' => null,
            'failed_attempts' => 0,
        ])->save();

        $this->audit->log('account_unblocked', $user, $old, ['how' => $how], $admin);
    }

    /** Auto-lift expired 24h blocks (scheduler + opportunistic check at login). */
    public function liftExpiredBlock(User $user): bool
    {
        if ($user->status === User::STATUS_BLOCKED
            && $user->blocked_until !== null
            && $user->blocked_until->isPast()) {
            $this->unblockAccount($user, null, 'expired');

            return true;
        }

        return false;
    }
}
