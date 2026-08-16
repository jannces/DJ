<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Delivery mode is decided by OtpService: sent inline by default so the code
 * arrives without a queue worker, queued when MAIL_QUEUE_OTP=true.
 */
class OtpCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $code,
        public int $ttlMinutes,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your One-Time Password (OTP) — LGU Alicia LMS');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.otp-code');
    }
}
