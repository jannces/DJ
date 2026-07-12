<?php

namespace App\Services\Auth;

use App\Mail\OtpCodeMail;
use App\Models\OtpCode;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * Email OTP second factor (ADR-003). Codes are stored hashed (SHA-256),
 * expire after a configurable TTL, are single-use and allow at most
 * 5 verification attempts before being invalidated.
 */
class OtpService
{
    public const MAX_ATTEMPTS = 5;

    public function enabled(): bool
    {
        return (bool) SystemSetting::get('auth.otp_enabled', true);
    }

    public function issue(User $user, string $purpose = 'login'): void
    {
        // Reissue invalidates any previous outstanding code (replay resistance).
        OtpCode::where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $code = (string) random_int(100000, 999999);
        $ttl = (int) SystemSetting::get('auth.otp_ttl_minutes', 5);

        OtpCode::create([
            'user_id' => $user->id,
            'code_hash' => hash('sha256', $code),
            'purpose' => $purpose,
            'expires_at' => now()->addMinutes($ttl),
            'ip' => app()->runningInConsole() ? null : request()->ip(),
        ]);

        Mail::to($user->email)->queue(new OtpCodeMail($user, $code, $ttl));
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
