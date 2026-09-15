<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use ZipArchive;

/**
 * The other half of `lms:backup`.
 *
 * Taking backups was already solved — scheduled nightly, on demand from the
 * browser, downloadable. Putting one back was not, and a backup nobody has
 * ever restored is a promise, not a safeguard. The Admin Guide told the
 * administrator to restore "per Deployment.md", and Deployment.md told them to
 * import the dump by hand with a MySQL client they may not have on the LAN box.
 *
 * Three decisions worth knowing about:
 *
 *   - It is deliberately a console command and not a button. Restoring throws
 *     away every leave application filed since the archive was taken. That
 *     belongs behind a terminal and a typed confirmation, not behind a click
 *     in the same page that lists the archives.
 *   - The dump already carries DROP TABLE / CREATE TABLE for every table and
 *     turns foreign keys off at the top, so this replays it statement by
 *     statement rather than trying to be clever about ordering.
 *   - There is no outer transaction. MySQL commits implicitly on DDL, so one
 *     would buy nothing there and would mislead here: SQLite ignores
 *     `PRAGMA foreign_keys` while a transaction is open, which is exactly how
 *     a half-restored database gets made. Instead the command says plainly
 *     which statement failed, and the archive is still on disk to try again.
 */
class RestoreSystem extends Command
{
    protected $signature = 'lms:restore
        {archive? : Path to a backup .zip (omit with --latest)}
        {--latest : Use the newest archive in storage/app/backups}
        {--force : Skip the confirmation prompt (unattended runs and tests)}
        {--documents-only : Put the uploaded documents back, leave the database alone}';

    protected $description = 'Restore the database and uploaded documents from a backup archive.';

    public function handle(): int
    {
        $archive = $this->archivePath();

        if ($archive === null) {
            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm(
            'This REPLACES the current database with the contents of '.basename($archive)
            .'. Everything recorded since that backup will be lost. Continue?'
        )) {
            $this->warn('Restore cancelled. Nothing was changed.');

            return self::FAILURE;
        }

        $zip = new ZipArchive;
        if ($zip->open($archive) !== true) {
            $this->error('That archive could not be opened. It may be truncated — check the file size against the one on the backup drive.');

            return self::FAILURE;
        }

        $work = storage_path('app/restore-'.now()->format('Ymd_His'));
        File::ensureDirectoryExists($work);
        $zip->extractTo($work);
        $zip->close();

        try {
            if (! $this->option('documents-only')) {
                $dump = collect(File::files($work))->first(fn ($f) => $f->getExtension() === 'sql');

                if (! $dump) {
                    $this->error('The archive holds no .sql dump, so there is nothing to restore into the database.');

                    return self::FAILURE;
                }

                $this->info('Restoring the database…');
                $this->importDump($dump->getRealPath());
            }

            $documents = $work.'/documents';
            if (File::isDirectory($documents)) {
                File::copyDirectory($documents, storage_path('app/private/leave-documents'));
                $this->info('Uploaded documents restored.');
            }
        } finally {
            File::deleteDirectory($work);
        }

        $this->newLine();
        $this->info('Restore complete. Check the employee count and the newest leave application before letting anyone back in.');

        return self::SUCCESS;
    }

    /** The archive the operator asked for, or the newest one. */
    private function archivePath(): ?string
    {
        if ($this->option('latest')) {
            $directory = storage_path('app/backups');
            $newest = collect(File::isDirectory($directory) ? File::files($directory) : [])
                ->filter(fn ($f) => $f->getExtension() === 'zip')
                ->sortByDesc(fn ($f) => $f->getMTime())
                ->first();

            if (! $newest) {
                $this->error('No backup archives in '.$directory.' — take one with `php artisan lms:backup` first.');

                return null;
            }

            return $newest->getRealPath();
        }

        $archive = $this->argument('archive');

        if (! $archive) {
            $this->error('Name an archive to restore, or pass --latest for the newest one.');

            return null;
        }

        if (! File::exists($archive)) {
            $this->error("Backup archive not found: {$archive}");

            return null;
        }

        return $archive;
    }

    /**
     * Replay the dump.
     *
     * Statements are split on a semicolon at the end of a line, which is how
     * the dump writes them: one statement per line, values already quoted by
     * PDO. A value containing "\n;" would defeat a smarter parser too, and the
     * dump never produces one.
     */
    private function importDump(string $path): void
    {
        $schema = DB::getSchemaBuilder();
        $schema->disableForeignKeyConstraints();

        $handle = fopen($path, 'r');
        $buffer = '';
        $executed = 0;

        try {
            while (($line = fgets($handle)) !== false) {
                $trimmed = trim($line);

                if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                    continue;
                }

                $buffer .= $line;

                if (! str_ends_with($trimmed, ';')) {
                    continue;
                }

                $statement = trim($buffer);
                $buffer = '';

                try {
                    DB::unprepared($statement);
                    $executed++;
                } catch (\Throwable $e) {
                    $this->error('Failed on statement '.($executed + 1).': '.$this->summarise($statement));
                    $this->error($e->getMessage());
                    $this->warn('The database is now part-restored. The archive is untouched — fix the cause and run the restore again.');

                    throw $e;
                }
            }
        } finally {
            fclose($handle);
            $schema->enableForeignKeyConstraints();
        }

        $this->line("       {$executed} statement(s) replayed.");
    }

    /** First few words of a statement, for an error line that has to fit on a screen. */
    private function summarise(string $statement): string
    {
        return str($statement)->squish()->limit(80)->toString();
    }
}
