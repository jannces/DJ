<?php

namespace App\Console\Commands;

use App\Services\Auth\OtpService;
use Illuminate\Console\Command;

class PruneOtpCodes extends Command
{
    protected $signature = 'otp:prune';

    protected $description = 'Delete OTP codes older than a day.';

    public function handle(OtpService $otp): int
    {
        $deleted = $otp->pruneExpired();
        $this->info("Pruned {$deleted} expired OTP code(s).");

        return self::SUCCESS;
    }
}
