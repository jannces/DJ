<?php

namespace App\Services\Auth;

use App\Mail\OtpCodeMail;
use App\Models\OtpCode;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

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

    /**
     * Generate a code, store its hash and deliver it to the account's real
     * mailbox. Returns false when the mail transport rejected the message so
     * callers can tell the user instead of leaving them on a silent OTP screen.
     */
    public function issue(User $user, string $purpose = 'login'): bool
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

        return $this->deliver($user, $code, $ttl);
    }

    /**
     * Hand the mailable to the configured transport. Queueing keeps login fast
     * but only works when a worker is running, so it stays opt-in
     * (MAIL_QUEUE_OTP) and never applies to the "sync" queue driver.
     */
    private function deliver(User $user, string $code, int $ttl): bool
    {
        $mailable = new OtpCodeMail($user, $code, $ttl);

        try {
            if (config('mail.otp_queue') && config('queue.default') !== 'sync') {
                Mail::to($user->email)->queue($mailable);
            } else {
                Mail::to($user->email)->send($mailable);
            }
        } catch (Throwable $e) {
            // Never log the code itself — only why delivery failed.
            Log::error('OTP email delivery failed', [
                'user_id' => $user->id,
                'mailer' => config('mail.default'),
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * "juan.delacruz@alicia.gov.ph" => "jua***uz@alicia.gov.ph" — enough for the
     * user to recognise the mailbox without exposing it on screen in full.
     */
    public function maskedRecipient(User $user): string
    {
        [$local, $domain] = array_pad(explode('@', (string) $user->email, 2), 2, '');

        if ($domain === '') {
            return $user->email;
        }

        $visible = mb_strlen($local) <= 4
            ? mb_substr($local, 0, 1)
            : mb_substr($local, 0, 3).'***'.mb_substr($local, -2);

        return $visible.'@'.$domain;
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
