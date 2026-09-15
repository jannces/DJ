<?php

namespace App\Console\Commands;

use App\Support\DatabaseReachability;
use Illuminate\Console\Command;

/**
 * Is the database up? Exit 0 if yes, 1 with a sentence if no.
 *
 * For update.bat, which runs `php artisan migrate` and, when MySQL is not
 * running, produced eight frames of Laravel internals ending in
 *
 *   SQLSTATE[HY000] [2002] No connection could be made because the target
 *   machine actively refused it
 *
 * followed by "The database update failed." Neither said the thing that would
 * have fixed it in ten seconds, and by then the script had already switched
 * branch, pulled new code and run composer -- so its closing "Nothing further
 * was changed" was not true either.
 *
 * A separate command rather than a flag on lms:backup because a check should
 * not be a side effect of doing something else, and because update.bat wants
 * the answer before it has decided to back anything up.
 */
class CheckDatabase extends Command
{
    protected $signature = 'lms:db-check';

    protected $description = 'Check the database is reachable; exits non-zero with the reason if not.';

    public function handle(): int
    {
        if (($problem = DatabaseReachability::problem()) !== null) {
            $this->error($problem);

            return self::FAILURE;
        }

        $config = \Illuminate\Support\Facades\DB::connection()->getConfig();

        $this->info(sprintf('Database reachable: %s at %s:%s.',
            $config['database'] ?? '?', $config['host'] ?? '?', $config['port'] ?? '?'));

        return self::SUCCESS;
    }
}
