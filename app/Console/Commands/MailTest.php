<?php

namespace App\Console\Commands;

use App\Mail\OtpCodeMail;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Verifies the configured mail transport by delivering a sample OTP email,
 * so SMTP credentials can be proven before anyone tries to log in.
 */
class MailTest extends Command
{
    protected $signature = 'mail:test {email : Mailbox that should receive the sample OTP}';

    protected $description = 'Send a sample OTP email to confirm the mail transport works.';

    public function handle(): int
    {
        $email = (string) $this->argument('email');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("\"{$email}\" is not a valid email address.");

            return self::FAILURE;
        }

        $mailer = config('mail.default');
        $this->line("Mailer: <info>{$mailer}</info> from <info>".config('mail.from.address').'</info>');

        if ($mailer === 'log') {
            $this->warn('MAIL_MAILER=log writes the message to storage/logs/laravel.log instead of sending it.');
        }

        $recipient = User::where('email', $email)->first() ?? new User([
            'name' => 'Mail Test',
            'email' => $email,
        ]);

        try {
            Mail::to($email)->send(new OtpCodeMail($recipient, '123456', 5));
        } catch (Throwable $e) {
            $this->error('Delivery failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Sample OTP email handed to the {$mailer} transport for {$email}.");

        return self::SUCCESS;
    }
}
