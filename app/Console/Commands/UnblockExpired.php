<?php

namespace App\Console\Commands;

use App\Models\BlockedIp;
use App\Models\User;
use App\Services\Auth\LoginSecurityService;
use Illuminate\Console\Command;

class UnblockExpired extends Command
{
    protected $signature = 'security:unblock-expired';

    protected $description = 'Lift expired account and IP blocks.';

    public function handle(LoginSecurityService $loginSecurity): int
    {
        $accounts = User::where('status', User::STATUS_BLOCKED)
            ->whereNotNull('blocked_until')->where('blocked_until', '<', now())->get();
        foreach ($accounts as $user) {
            $loginSecurity->liftExpiredBlock($user);
        }

        $ips = BlockedIp::where('active', true)->whereNotNull('expires_at')->where('expires_at', '<', now())->update(['active' => false]);

        $this->info("Unblocked {$accounts->count()} account(s) and {$ips} IP(s).");

        return self::SUCCESS;
    }
}
