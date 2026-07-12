<?php

namespace App\Console\Commands;

use App\Models\LeaveType;
use App\Models\User;
use App\Services\Leave\LeaveCreditService;
use Illuminate\Console\Command;

class AccrueLeaveCredits extends Command
{
    protected $signature = 'leave:accrue {--period= : YYYY-MM to accrue (defaults to current month)}';

    protected $description = 'Credit monthly Vacation and Sick Leave (1.25 each) to active employees (idempotent).';

    public function handle(LeaveCreditService $credits): int
    {
        $period = $this->option('period') ?: now()->format('Y-m');
        $vl = LeaveType::where('code', 'VL')->first();
        $sl = LeaveType::where('code', 'SL')->first();

        if (! $vl || ! $sl) {
            $this->error('VL/SL leave types are not seeded.');

            return self::FAILURE;
        }

        $count = 0;
        User::where('status', User::STATUS_ACTIVE)->whereHas('employeeProfile')->chunkById(100, function ($users) use (&$count, $credits, $vl, $sl, $period) {
            foreach ($users as $user) {
                $a = $credits->accrue($user, $vl, $period);
                $b = $credits->accrue($user, $sl, $period);
                if ($a || $b) {
                    $count++;
                }
            }
        });

        $this->info("Accrued leave credits for {$count} employee(s) for {$period}.");

        return self::SUCCESS;
    }
}
