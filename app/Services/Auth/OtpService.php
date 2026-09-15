<?php

namespace App\Services\Auth;

use App\Mail\OtpCodeMail;
use App\Models\OtpCode;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Email OTP second factor (ADR-003). Codes are stored hashed (SHA-256),
 * expire after a configurable TTL, are single-use and allow at most
 * 5 verification attempts before being invalidated.
 *
 * What happens to a code after it is minted is recorded too. A mail relay that
 * is down must not read to the employee as a code that is merely slow, and it
 * must not be a reason to switch the second factor off for the whole LGU: the
 * OTP screen says delivery failed, and an administrator can read out a single
 * replacement code with their own name attached to it in the audit trail.
 */
class OtpService
{
    public const MAX_ATTEMPTS = 5;

    public function enabled(): bool
    {
        return (bool) SystemSetting::get('auth.otp_enabled', true);
    }

    public function issue(User $user, string $purpose = 'login'): OtpCode
    {
        [$otp, $code, $ttl] = $this->mint($user, $purpose);

        try {
            Mail::to($user->email)->queue(new OtpCodeMail($user, $code, $ttl));
            $otp->update(['delivery_status' => OtpCode::DELIVERY_SENT]);
        } catch (\Throwable $e) {
            // A refused hand-off is not an application error: the password step
            // already succeeded and the session is waiting at the OTP screen.
            // Record why, so that screen can say something useful and the
            // administrator has something to act on.
            $otp->update([
                'delivery_status' => OtpCode::DELIVERY_FAILED,
                'delivery_error' => str($e->getMessage())->squish()->limit(255)->toString(),
            ]);
            Log::warning('OTP delivery failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }

        return $otp->refresh();
    }

    /**
     * A single code an administrator reads out in person.
     *
     * For the mail relay being down, and for the employee whose recorded
     * mailbox nobody has opened in a year. It is an ordinary code in every
     * respect — same expiry, single use, invalidates whatever was outstanding —
     * so nothing about the verification path has to trust it differently. What
     * makes it accountable is the administrator's id on the row and the entry
     * their controller writes to the audit trail.
     *
     * The plain code is returned once and never stored: the caller shows it to
     * the administrator and forgets it.
     */
    public function issueBypass(User $user, User $admin, string $purpose = 'login'): string
    {
        [$otp, $code] = $this->mint($user, $purpose);

        $otp->update([
            'delivery_status' => OtpCode::DELIVERY_BYPASS,
            'issued_by_id' => $admin->id,
        ]);

        return $code;
    }

    /** The most recent code for this user, whatever became of it. */
    public function lastIssued(User $user, string $purpose = 'login'): ?OtpCode
    {
        return OtpCode::where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->latest('id')
            ->first();
    }

    /**
     * Mint and store a code, retiring any that is still outstanding.
     *
     * @return array{0: OtpCode, 1: string, 2: int}
     */
    private function mint(User $user, string $purpose): array
    {
        // Reissue invalidates any previous outstanding code (replay resistance).
        OtpCode::where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $code = (string) random_int(100000, 999999);
        $ttl = (int) SystemSetting::get('auth.otp_ttl_minutes', 5);

        $otp = OtpCode::create([
            'user_id' => $user->id,
            'code_hash' => hash('sha256', $code),
            'purpose' => $purpose,
            'delivery_status' => OtpCode::DELIVERY_PENDING,
            'expires_at' => now()->addMinutes($ttl),
            'ip' => app()->runningInConsole() ? null : request()->ip(),
        ]);

        return [$otp, $code, $ttl];
    }

    public function verify(User $user, string $code, string $purpose = 'login'): bool
    {
        $otp = OtpCode::where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();

        if (! $otp || ! $otp->isUsable()) {
            return false;
        }

        if (! hash_equals($otp->code_hash, hash('sha256', $code))) {
            $otp->increment('attempts');

            return false;
        }

        $otp->update(['consumed_at' => now()]);

        return true;
    }

    public function pruneExpired(): int
    {
        return OtpCode::where('expires_at', '<', now()->subDay())->delete();
    }
}
