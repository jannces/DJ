<?php

namespace App\Console\Commands;

use App\Models\AuthorizedDevice;
use Illuminate\Console\Command;

/** Emergency CLI device registration (e.g. if enforcement locks admins out). */
class AddAuthorizedDevice extends Command
{
    protected $signature = 'lms:device:add {ip} {hostname} {--description=}';

    protected $description = 'Register an authorized device from the command line.';

    public function handle(): int
    {
        $device = AuthorizedDevice::updateOrCreate(
            ['ip_address' => $this->argument('ip')],
            [
                'hostname' => $this->argument('hostname'),
                'description' => $this->option('description') ?: 'Added via CLI',
                'status' => 'active',
                'archived_at' => null,
            ],
        );

        $this->info("Authorized device {$device->ip_address} ({$device->hostname}) is active.");

        return self::SUCCESS;
    }
}
