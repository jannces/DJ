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

    public function handle(): int
    {
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

        $zipPath = "{$dir}/lms_{$stamp}.zip";
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFile($sqlPath, "db_{$stamp}.sql");

        // Include uploaded leave documents.
        $docsRoot = storage_path('app/private/leave-documents');
        if (File::isDirectory($docsRoot)) {
            foreach (File::allFiles($docsRoot) as $file) {
                $zip->addFile($file->getRealPath(), 'documents/'.$file->getRelativePathname());
            }
        }
        $zip->close();
        File::delete($sqlPath);

        $this->info("Backup created: {$zipPath} (".round(filesize($zipPath) / 1024, 1).' KB)');

        return self::SUCCESS;
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
        $handle = fopen($path, 'w');
        fwrite($handle, '-- LMS portable backup '.now()->toDateTimeString()." ({$driver})\n");

        $tables = $this->tables($driver);

        fwrite($handle, $driver === 'mysql'
            ? "SET FOREIGN_KEY_CHECKS=0;\n"
            : "PRAGMA foreign_keys=OFF;\n");

        $pdo = DB::connection()->getPdo();
        $q = $driver === 'mysql' ? '`' : '"';

        foreach ($tables as $table => $create) {
            $name = $q.$table.$q;

            fwrite($handle, "\nDROP TABLE IF EXISTS {$name};\n");
            fwrite($handle, rtrim($create, ";\n").";\n");

            foreach (DB::table($table)->cursor() as $row) {
                $data = (array) $row;
                $cols = implode(', ', array_map(fn ($c) => $q.$c.$q, array_keys($data)));
                $vals = implode(', ', array_map(
                    fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v),
                    $data,
                ));
                fwrite($handle, "INSERT INTO {$name} ({$cols}) VALUES ({$vals});\n");
            }
        }

        fwrite($handle, $driver === 'mysql'
            ? "\nSET FOREIGN_KEY_CHECKS=1;\n"
            : "\nPRAGMA foreign_keys=ON;\n");

        fclose($handle);
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

            $row = (array) DB::select("SHOW CREATE TABLE `{$name}`")[0]; // @sql-identifier
            $out[$name] = $row['Create Table'] ?? array_values($row)[1];
        }

        return $out;
    }
}
