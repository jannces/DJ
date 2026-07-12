<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class IntrusionAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $ip, public int $events)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Security alert: IP auto-blocked — LGU Alicia LMS')
            ->error()
            ->line("The IP address {$this->ip} was automatically blocked after {$this->events} intrusion events.")
            ->action('Open Security Dashboard', url(route('security.dashboard')))
            ->line('Review the intrusion logs and unblock manually if this was a false positive.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'IP auto-blocked',
            'message' => "IP {$this->ip} blocked after {$this->events} intrusion events.",
            'ip' => $this->ip,
            'url' => route('security.dashboard'),
        ];
    }
}
