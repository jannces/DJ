<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use ZipArchive;

/**
 * Creates a timestamped backup zip containing a database dump and the uploaded
 * documents.
 *
 * Two ways of dumping, in order of preference:
 *
 *   1. mysqldump, when it can be found. It is the only one that produces a
 *      file the LGU's own IT would recognise.
 *   2. A portable dump written here through PDO, for a machine without it and
 *      for the SQLite database the tests run on.
 *
 * Both write schema AND data. An earlier version of the portable path wrote
 * INSERT statements only, which is not a backup: restoring it required a
 * database that already had every table, so it could not rebuild anything
 * after the failure a backup exists for.
 *
 * One table that cannot be read does not stop the rest. See $skipped.
 */
class BackupSystem extends Command
{
    protected $signature = 'lms:backup {--path= : Output directory (default storage/app/backups)}';

    protected $description = 'Back up the database and uploaded documents to a zip archive.';

    /**
     * What a table name is allowed to look like before it is put into a
     * statement. See tables(): an identifier cannot be a bound parameter, so
     * this pattern is the check that stands in for one.
     */
    private const IDENTIFIER = '/^[A-Za-z0-9_]+$/';

    /**
     * Tables this run could not read, and why. table name => reason.
     *
     * The backup used to be all or nothing, and a real install showed why that
     * is the wrong way round. InnoDB lost the tablespace for activity_logs --
     * a LOG table -- and the dump threw:
     *
     *   SQLSTATE[42S02]: Base table or view not found: 1932
     *   Table 'lms_alicia.activity_logs' doesn't exist in engine
     *
     * so nothing was written at all. Every leave request, every employee
     * record, every uploaded document in that database was perfectly healthy
     * and perfectly unsaveable, because one audit table was damaged. That is
     * exactly backwards: the moment a database starts to break is the moment
     * you most want whatever of it still reads.
     *
     * So a table that cannot be read is now recorded here and skipped, the
     * archive is written from everything that can, and the run still ends in
     * FAILURE -- the archive is incomplete and nothing downstream (update.bat
     * above all) may treat it as a safety net. The filename says so too.
     *
     * @var array<string, string>
     */
    private array $skipped = [];

    /** How many tables were written, so the summary can say "3 of 41". */
    private int $dumped = 0;

    public function handle(): int
    {
        $this->skipped = [];
        $this->dumped = 0;

        // Before anything else, and separately from the dump, because a
        // database that is not running is not a dump failure -- it is the one
        // thing this command cannot work around, and it has a one-sentence fix.
        //
        // Without this the run said "mysqldump failed; using the portable dump
        // instead" (true, and misleading -- mysqldump failed because there was
        // nothing to connect to) and then threw a QueryException with a stack
        // trace. Two screens of Laravel internals for "MySQL is not running".
        if (($problem = $this->databaseProblem()) !== null) {
            $this->error($problem);

            return self::FAILURE;
        }

        $dir = $this->option('path') ?: storage_path('app/backups');
        File::ensureDirectoryExists($dir);
        $stamp = now()->format('Ymd_His');
        $sqlPath = "{$dir}/db_{$stamp}.sql";

        $this->info('Dumping database…');

        try {
            $this->dumpDatabase($sqlPath);
        } catch (\Throwable $e) {
            @unlink($sqlPath);
            $this->error('The dump failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! is_file($sqlPath) || filesize($sqlPath) === 0) {
            $this->error('The dump produced no data. No archive was written.');
            @unlink($sqlPath);

            return self::FAILURE;
        }

        // A partial archive is never named like a whole one. Somebody reaching
        // for this file is restoring a system that is already broken, probably
        // in a hurry, and "lms_20260922_141230.zip" tells them nothing is
        // missing. "lms_partial_..." tells them before they open it.
        $partial = $this->skipped !== [];
        $zipPath = $dir.'/lms_'.($partial ? 'partial_' : '').$stamp.'.zip';

        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFile($sqlPath, "db_{$stamp}.sql");

        if ($partial) {
            $zip->addFromString('READ-ME-FIRST.txt', $this->partialNotice());
        }

        // Include uploaded leave documents.
        $docsRoot = storage_path('app/private/leave-documents');
        if (File::isDirectory($docsRoot)) {
            foreach (File::allFiles($docsRoot) as $file) {
                $zip->addFile($file->getRealPath(), 'documents/'.$file->getRelativePathname());
            }
        }
        $zip->close();
        File::delete($sqlPath);

        $size = round(filesize($zipPath) / 1024, 1);

        if ($partial) {
            foreach ($this->skipped as $table => $reason) {
                $this->warn("       Could not read {$table}: {$reason}");
            }

            // Last, because BackupController shows the command's final line on
            // the Backups page and update.bat prints it in the terminal. The
            // sentence has to carry the whole meaning on its own: something was
            // saved, something was not, and the update must not go ahead.
            $this->error(sprintf(
                'INCOMPLETE backup written to %s (%s KB): %d of %d table(s) could not be read (%s). '
                .'Everything else was saved. Do NOT update or migrate until the database is repaired.',
                $zipPath, $size, count($this->skipped),
                count($this->skipped) + $this->dumped, implode(', ', array_keys($this->skipped)),
            ));

            return self::FAILURE;
        }

        $this->info("Backup created: {$zipPath} ({$size} KB)");

        return self::SUCCESS;
    }

    /** The file that goes in a partial archive, addressed to whoever opens it. */
    private function partialNotice(): string
    {
        $lines = [
            'THIS BACKUP IS INCOMPLETE.',
            '',
            'Taken '.now()->toDayDateTimeString().'.',
            '',
            'The database could not be read in full. These tables are MISSING or',
            'only partly present in db_*.sql:',
            '',
        ];

        foreach ($this->skipped as $table => $reason) {
            $lines[] = "  - {$table}: {$reason}";
        }

        return implode("\n", array_merge($lines, [
            '',
            'Every other table was read normally and is complete in this archive.',
            '',
            'Restoring this file rebuilds the system WITHOUT the tables listed above.',
            '',
            'A message about a MISSING TABLESPACE, or a table that "doesn\'t exist in',
            'engine" (error 1932 on MySQL, error 194 on MariaDB), means the server still',
            'lists the table but has lost the file holding it -- usually after MySQL was',
            'shut down uncleanly. The rest of the database is unaffected, which is why',
            'this archive exists.',
            '',
            'Run "php artisan lms:db-check --tables" for the current state of every',
            'table before deciding what to restore.',
            '',
        ]));
    }

    /**
     * Why the database cannot be reached, in one line, or null if it can.
     *
     * The wording lives in App\Support\DatabaseReachability because update.bat
     * needs the same answer before it migrates, and a second copy of this
     * reasoning would drift from the first.
     */
    private function databaseProblem(): ?string
    {
        return \App\Support\DatabaseReachability::problem();
    }

    private function dumpDatabase(string $path): void
    {
        // The DRIVER, not the connection name. They are usually the same and
        // do not have to be: a connection named "mysql" can be pointed at
        // anything, and it was the connection name being trusted that sent a
        // MySQL database down the SQLite path.
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql' && ($binary = $this->dumpBinary()) !== null) {
            if ($this->runMysqldump($binary, $path)) {
                $this->line('       Dumped with '.basename($binary).'.');

                return;
            }

            $this->warn('       mysqldump failed; using the portable dump instead.');
        }

        $this->portableDump($path, $driver);
        $this->line('       Dumped through PDO (portable).');
    }

    /**
     * Where mysqldump actually is.
     *
     * `mysqldump --version` alone was the whole test, and on XAMPP it fails:
     * the binary sits in xampp\mysql\bin, which is not on PATH. Every XAMPP
     * install therefore fell through to the portable path without saying so.
     */
    private function dumpBinary(): ?string
    {
        $candidates = [];

        // An explicit answer always wins, for an install that keeps it
        // somewhere none of the guesses below would look.
        if ($explicit = env('DB_DUMP_BINARY')) {
            $candidates[] = $explicit;
        }

        // XAMPP relative to the PHP that is running this. Works for C:\xampp,
        // D:\xampp and anywhere else it was installed, without being told.
        if (PHP_BINARY) {
            $xampp = dirname(PHP_BINARY, 2);
            $candidates[] = $xampp.'\\mysql\\bin\\mysqldump.exe';
            $candidates[] = $xampp.'\\mysql\\bin\\mariadb-dump.exe';
        }

        $candidates[] = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
        $candidates[] = 'D:\\xampp\\mysql\\bin\\mysqldump.exe';

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        // And on PATH, which covers Linux and a Windows box that has it set
        // up. mariadb-dump is what newer MariaDB ships; XAMPP has been
        // MariaDB for years and the mysqldump name is on its way out.
        foreach (['mysqldump', 'mariadb-dump'] as $name) {
            if ($this->exec($name.' --version') === 0) {
                return $name;
            }
        }

        return null;
    }

    private function runMysqldump(string $binary, string $path): bool
    {
        $config = DB::connection()->getConfig();

        // Credentials go in a temporary defaults file, never on the command
        // line, where any other account on this machine can read them out of
        // the process list while the dump runs.
        $defaults = tempnam(sys_get_temp_dir(), 'lmsdump');

        if ($defaults === false) {
            return false;
        }

        @chmod($defaults, 0600);
        file_put_contents($defaults, implode("\n", [
            '[client]',
            'user="'.$config['username'].'"',
            'password="'.($config['password'] ?? '').'"',
            'host="'.$config['host'].'"',
            'port='.($config['port'] ?: 3306),
        ])."\n");

        // --result-file rather than "> file": shell redirection is one more
        // thing to quote correctly on Windows, and it writes the file itself.
        // --skip-lock-tables because the application's database user is not
        // necessarily granted LOCK TABLES, and a dump that refuses over a
        // privilege is no dump at all.
        $code = $this->exec(sprintf(
            '%s --defaults-extra-file=%s --single-transaction --skip-lock-tables --routines --add-drop-table --result-file=%s %s',
            escapeshellarg($binary),
            escapeshellarg($defaults),
            escapeshellarg($path),
            escapeshellarg($config['database']),
        ));

        @unlink($defaults);

        return $code === 0 && is_file($path) && filesize($path) > 0;
    }

    /** exec() is disabled on some hardened PHP builds; that is not a crash. */
    private function exec(string $command): int
    {
        if (! function_exists('exec')) {
            return 1;
        }

        $output = [];
        $code = 1;
        @exec($command.' 2>&1', $output, $code);

        return $code;
    }

    /**
     * A dump written here, through PDO, for when mysqldump is not available.
     *
     * Schema first, then rows, so the file can rebuild a database from
     * nothing. Foreign keys are switched off around the whole thing: tables
     * are written in whatever order the server lists them, and a child row
     * inserted before its parent would otherwise be rejected.
     */
    private function portableDump(string $path, string $driver): void
    {
        // Read the catalogue BEFORE opening the file, so a table that cannot
        // even be described is already known when the header is written.
        $tables = $this->tables($driver);

        $handle = fopen($path, 'w');
        fwrite($handle, '-- LMS portable backup '.now()->toDateTimeString()." ({$driver})\n");

        foreach ($this->skipped as $table => $reason) {
            fwrite($handle, "-- WARNING: {$table} could not be read and is NOT in this file ({$reason}).\n");
        }

        fwrite($handle, $driver === 'mysql'
            ? "SET FOREIGN_KEY_CHECKS=0;\n"
            : "PRAGMA foreign_keys=OFF;\n");

        $pdo = DB::connection()->getPdo();
        $q = $driver === 'mysql' ? '`' : '"';

        foreach ($tables as $table => $create) {
            $name = $q.$table.$q;

            fwrite($handle, "\nDROP TABLE IF EXISTS {$name};\n");
            fwrite($handle, rtrim($create, ";\n").";\n");

            // Describing a table and reading it are two different privileges
            // and two different pages on disk: SHOW CREATE TABLE can answer
            // from the dictionary while the rows themselves are gone. So the
            // rows get their own guard rather than sharing the one in tables().
            try {
                foreach (DB::table($table)->cursor() as $row) {
                    $data = (array) $row;
                    $cols = implode(', ', array_map(fn ($c) => $q.$c.$q, array_keys($data)));
                    $vals = implode(', ', array_map(
                        fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v),
                        $data,
                    ));
                    fwrite($handle, "INSERT INTO {$name} ({$cols}) VALUES ({$vals});\n");
                }

                $this->dumped++;
            } catch (\Throwable $e) {
                // The structure is already written and is worth keeping -- a
                // restore then rebuilds the table empty rather than not at all.
                // The rows written before the failure stay too; they are whole
                // statements, since each is written in one fwrite.
                $this->skipped[$table] = $this->reason($e);
                fwrite($handle, "-- WARNING: rows of {$table} stop here; the table could not be read to the end.\n");
            }
        }

        fwrite($handle, $driver === 'mysql'
            ? "\nSET FOREIGN_KEY_CHECKS=1;\n"
            : "\nPRAGMA foreign_keys=ON;\n");

        // Every skip, in one place, at the end. The header above can only name
        // the tables that failed to describe -- a table whose rows run out
        // part-way is not known to have failed until it has been written. One
        // closing block covers both, so there is a single list to read.
        if ($this->skipped !== []) {
            fwrite($handle, "\n-- ================ THIS DUMP IS INCOMPLETE ================\n");

            foreach ($this->skipped as $table => $reason) {
                fwrite($handle, "-- WARNING: {$table} -- {$reason}\n");
            }

            fwrite($handle, "-- See READ-ME-FIRST.txt in the archive.\n");
        }

        fclose($handle);
    }

    /** @see \App\Support\DatabaseReachability::reason() */
    private function reason(\Throwable $e): string
    {
        return \App\Support\DatabaseReachability::reason($e);
    }

    /**
     * Table name => the CREATE statement that rebuilds it.
     *
     * Asked of the driver in front of us. The previous version ran the SQLite
     * catalogue query unconditionally and expected an empty result on MySQL
     * to trigger a fallback -- but the query does not return empty there, it
     * throws, so the fallback was unreachable and every MySQL backup died on:
     *
     *   SQLSTATE[42S02]: Base table or view not found: 1146
     *   Table 'lms_alicia.sqlite_master' doesn't exist
     *
     * @return array<string, string>
     */
    private function tables(string $driver): array
    {
        if ($driver === 'sqlite') {
            return collect(DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"))
                ->mapWithKeys(fn ($r) => [$r->name => $r->sql])->all();
        }

        // BASE TABLE only: a view has no rows of its own, and CREATE TABLE is
        // not how one is rebuilt. Both values are bindings.
        $names = collect(DB::select('SELECT table_name AS n FROM information_schema.tables WHERE table_schema = ? AND table_type = ? ORDER BY table_name', [DB::connection()->getDatabaseName(), 'BASE TABLE']))->pluck('n');

        $out = [];

        foreach ($names as $name) {
            // SQL cannot bind an identifier -- a table name is part of the
            // statement, not a value in it -- so this is the one place in the
            // application that interpolates into raw SQL, and it is marked so
            // the guard in SqlInjectionTest can hold it to a rule instead of
            // being quietly widened.
            //
            // Two things make it safe. The name never comes from a request: it
            // was just read from information_schema for this schema. And it is
            // checked against IDENTIFIER anyway, because "it cannot happen" is
            // the reasoning that puts holes in systems.
            if (! preg_match(self::IDENTIFIER, (string) $name)) {
                $this->warn("       Skipped table with an unexpected name: {$name}");

                continue;
            }

            // information_schema listing a table does not mean the storage
            // engine can produce it. When InnoDB has lost the tablespace the
            // name is still in the catalogue and this line throws 1932 -- the
            // failure that used to end the whole backup. Record it and move on
            // to the next table; handle() decides what that means for the run.
            try {
                $row = (array) DB::select("SHOW CREATE TABLE `{$name}`")[0]; // @sql-identifier
                $out[$name] = $row['Create Table'] ?? array_values($row)[1];
            } catch (\Throwable $e) {
                $this->skipped[$name] = $this->reason($e);
            }
        }

        return $out;
    }
}
