<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use ZipArchive;

/**
 * Creates a timestamped backup zip containing a database dump and the uploaded
 * documents. Uses mysqldump when available, otherwise a portable PHP dump so it
 * works on stock XAMPP and on the SQLite dev database alike.
 */
class BackupSystem extends Command
{
    protected $signature = 'lms:backup {--path= : Output directory (default storage/app/backups)}';

    protected $description = 'Back up the database and uploaded documents to a zip archive.';

    public function handle(): int
    {
        $dir = $this->option('path') ?: storage_path('app/backups');
        File::ensureDirectoryExists($dir);
        $stamp = now()->format('Ymd_His');
        $sqlPath = "{$dir}/db_{$stamp}.sql";

        $this->info('Dumping database…');
        $this->dumpDatabase($sqlPath);

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

        $this->info("Backup created: {$zipPath} (".round(filesize($zipPath) / 1024, 1)." KB)");

        return self::SUCCESS;
    }

    private function dumpDatabase(string $path): void
    {
        $connection = config('database.default');
        $config = config("database.connections.{$connection}");

        if ($connection === 'mysql' && $this->hasMysqldump()) {
            $cmd = sprintf(
                'mysqldump --host=%s --port=%s --user=%s %s %s > %s',
                escapeshellarg($config['host']),
                escapeshellarg((string) $config['port']),
                escapeshellarg($config['username']),
                $config['password'] ? '--password='.escapeshellarg($config['password']) : '',
                escapeshellarg($config['database']),
                escapeshellarg($path),
            );
            exec($cmd, $o, $code);
            if ($code === 0) {
                return;
            }
        }

        // Portable fallback: dump every table via PDO as INSERT statements.
        $this->portableDump($path);
    }

    private function hasMysqldump(): bool
    {
        exec('mysqldump --version 2>&1', $o, $code);

        return $code === 0;
    }

    private function portableDump(string $path): void
    {
        $tables = collect(DB::select('SELECT name FROM sqlite_master WHERE type = "table"'))
            ->pluck('name')
            ->reject(fn ($t) => str_starts_with($t, 'sqlite_'));

        // On MySQL the query above fails; fall back to SHOW TABLES.
        if ($tables->isEmpty()) {
            $tables = collect(DB::select('SHOW TABLES'))->map(fn ($r) => array_values((array) $r)[0]);
        }

        $handle = fopen($path, 'w');
        fwrite($handle, "-- LMS portable backup ".now()."\n");
        foreach ($tables as $table) {
            foreach (DB::table($table)->get() as $row) {
                $data = (array) $row;
                $cols = implode(', ', array_keys($data));
                $vals = implode(', ', array_map(fn ($v) => $v === null ? 'NULL' : DB::connection()->getPdo()->quote((string) $v), $data));
                fwrite($handle, "INSERT INTO {$table} ({$cols}) VALUES ({$vals});\n");
            }
        }
        fclose($handle);
    }
}
