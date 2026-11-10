<?php

namespace Tests\Feature\Admin;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * What the backup does when the database is not running.
 *
 * A real run, on the XAMPP box, with MySQL stopped:
 *
 *   mysqldump failed; using the portable dump instead.
 *   Illuminate\Database\QueryException
 *   SQLSTATE[HY000] [2002] No connection could be made because the target
 *   machine actively refused it
 *   at vendor\laravel\framework\src\Illuminate\Database\Connection.php:838
 *   ... eight frames of Laravel internals ...
 *
 * Both lines were misleading. mysqldump had not failed on its own account --
 * it failed because there was nothing to connect to, and the portable dump was
 * about to fail for the same reason. The person reading it needed one
 * sentence: MySQL is not running.
 *
 * DELIBERATELY IN ITS OWN CLASS, without RefreshDatabase. These tests repoint
 * database.default at a dead port; doing that inside a class that wraps each
 * test in a transaction on the default connection breaks the rollback and
 * fails every test that follows, which is how they were first written.
 */
class BackupDatabaseDownTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('app/backup-down-test');
        File::deleteDirectory($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /** A port nothing answers on, which is what a stopped MySQL looks like. */
    private function pointAtNothing(): void
    {
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.driver' => 'mysql',
            'database.connections.mysql.host' => '127.0.0.1',
            'database.connections.mysql.port' => 65123,
            'database.connections.mysql.database' => 'lms_alicia',
        ]);
        DB::purge('mysql');
    }

    public function test_an_unreachable_database_is_reported_not_thrown(): void
    {
        $this->pointAtNothing();

        $code = Artisan::call('lms:backup', ['--path' => $this->dir]);
        $output = Artisan::output();

        $this->assertSame(1, $code, 'the command reported success with no database');

        $this->assertStringContainsString('MySQL is not running', $output,
            'the failure does not name the cause');

        $this->assertStringNotContainsString('QueryException', $output,
            'a stack trace is being shown where a sentence belongs');
    }

    /** And nothing half-written is left to be mistaken for a backup. */
    public function test_no_archive_is_left_behind(): void
    {
        $this->pointAtNothing();

        Artisan::call('lms:backup', ['--path' => $this->dir]);

        $files = File::isDirectory($this->dir) ? File::files($this->dir) : [];

        $this->assertEmpty(
            collect($files)->filter(fn ($f) => in_array($f->getExtension(), ['zip', 'sql'], true)),
            'a file was written for a backup that never happened');
    }

    /**
     * The message is one line, because that is all the page shows.
     *
     * BackupController surfaces the command's LAST output line on the Backups
     * page. A diagnosis spread over several lines arrives there as its least
     * useful fragment.
     */
    public function test_the_diagnosis_and_the_fix_are_both_on_the_last_line(): void
    {
        $this->pointAtNothing();

        Artisan::call('lms:backup', ['--path' => $this->dir]);

        $last = trim((string) collect(explode("\n", trim(Artisan::output())))->last());

        $this->assertStringContainsString('MySQL is not running', $last,
            'the line the page shows does not carry the diagnosis');
        $this->assertStringContainsString('XAMPP Control Panel', $last,
            'the line the page shows does not carry the fix');
    }
}
