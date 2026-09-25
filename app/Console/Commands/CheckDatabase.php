<?php

namespace App\Console\Commands;

use App\Support\DatabaseReachability;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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
    protected $signature = 'lms:db-check {--tables : Also read every table, to find ones the engine has lost}';

    protected $description = 'Check the database is reachable; exits non-zero with the reason if not.';

    public function handle(): int
    {
        if (($problem = DatabaseReachability::problem()) !== null) {
            $this->error($problem);

            return self::FAILURE;
        }

        $config = DB::connection()->getConfig();

        $this->info(sprintf('Database reachable: %s at %s:%s.',
            $config['database'] ?? '?', $config['host'] ?? '?', $config['port'] ?? '?'));

        return $this->option('tables') ? $this->checkTables() : self::SUCCESS;
    }

    /**
     * Touch every table and report the ones that will not answer.
     *
     * Behind a flag, and deliberately not part of the plain check, for two
     * reasons. update.bat runs the plain check before it backs up, and a
     * damaged table must NOT stop it there -- a database that is starting to
     * break is precisely when the backup needs to run. And on a large database
     * this reads every table's first row, which the quick "is MySQL up?"
     * question has no business doing.
     *
     * What it is for is the question that comes after a failed backup: which
     * tables are damaged, and is it only the one? Nothing else answered that
     * without opening phpMyAdmin and clicking through every table in turn.
     */
    private function checkTables(): int
    {
        $driver = DB::connection()->getDriverName();

        try {
            $names = $driver === 'sqlite'
                ? collect(DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"))->pluck('name')
                : collect(DB::select('SELECT table_name AS n FROM information_schema.tables WHERE table_schema = ? AND table_type = ? ORDER BY table_name', [DB::connection()->getDatabaseName(), 'BASE TABLE']))->pluck('n');
        } catch (\Throwable $e) {
            $this->error('Could not list the tables: '.$e->getMessage());

            return self::FAILURE;
        }

        $broken = [];

        foreach ($names as $name) {
            try {
                // count() over limit(1) on purpose: it reads the table rather
                // than the dictionary, which is the whole point -- the
                // dictionary is the part that is still telling the truth.
                DB::table((string) $name)->count();
            } catch (\Throwable $e) {
                $broken[(string) $name] = DatabaseReachability::reason($e);
            }
        }

        if ($broken === []) {
            $this->info(sprintf('All %d table(s) readable.', $names->count()));

            return self::SUCCESS;
        }

        $this->newLine();

        foreach ($broken as $name => $message) {
            $this->line("  <fg=red>UNREADABLE</> {$name}");
            $this->line("             {$message}");
        }

        $this->newLine();
        $this->error(sprintf(
            '%d of %d table(s) cannot be read. Take a backup now (it will save the rest), then repair the database.',
            count($broken), $names->count(),
        ));

        return self::FAILURE;
    }
}
