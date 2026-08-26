<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A brute-force lockout, raised to the administrators.
 *
 * The failed-attempt threshold is the system's brute-force detector, so a
 * lockout is an intrusion detection — not a housekeeping event. Until this
 * existed it wrote a high-severity intrusion row and told nobody, which is the
 * one thing an IDS may not do.
 *
 * Two channels, and each answers a different situation:
 *
 *   · database — the topbar bell, for an administrator who is signed in;
 *   · mail     — for one who is not.
 *
 * Deliberately NOT queued, unlike the leave notifications. `QUEUE_CONNECTION`
 * defaults to `database`, and this system is deployed on a LAN box where
 * nobody is running `queue:work`. A queued security alert on that box is a row
 * in `jobs` that is never read — which would leave the alert looking built and
 * behaving like it was not. A lockout happens after three failures, so sending
 * it inline costs one mail on a rare path.
 */
class AccountLockoutAlertNotification extends Notification
{
    public function __construct(
        public string $account,
        public string $ip,
        public int $attempts,
        public ?string $until = null,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Security alert: account locked out — LGU Alicia LMS')
            ->error()
            ->line("The account {$this->account} was locked after {$this->attempts} failed sign-in attempts.")
            ->line("Last attempt came from {$this->ip}.");

        if ($this->until !== null) {
            $mail->line("The block lifts automatically at {$this->until}.");
        }

        return $mail
            ->action('Open Security Dashboard', url(route('security.dashboard')))
            ->line('If this was the account holder mistyping their password, unblock them from Users.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Account locked out',
            'message' => "{$this->account} locked after {$this->attempts} failed sign-in attempts from {$this->ip}.",
            'ip' => $this->ip,
            'url' => route('security.dashboard'),
        ];
    }
}
