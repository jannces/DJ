<?php

use App\Console\Commands\AccrueLeaveCredits;
use App\Console\Commands\PruneOtpCodes;
use App\Console\Commands\UnblockExpired;
use Illuminate\Support\Facades\Schedule;

// Monthly leave accrual (1st of month, 00:05).
Schedule::command(AccrueLeaveCredits::class)->monthlyOn(1, '00:05');
// Lift expired account/IP blocks every 5 minutes.
Schedule::command(UnblockExpired::class)->everyFiveMinutes();
// Prune old OTP codes hourly.
Schedule::command(PruneOtpCodes::class)->hourly();
// Nightly backup at 01:00.
Schedule::command('lms:backup')->dailyAt('01:00');
