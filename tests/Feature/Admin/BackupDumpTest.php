<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

/**
 * What is actually inside the archive.
 *
 * BackupTest covers who may press the button. This covers whether the thing
 * it produces is a backup, which is a different question and the one that was
 * wrong: the whole feature had only ever run against the SQLite database the
 * suite uses, and on MySQL -- the only database that matters in production --
 * it died on
 *
 *   SQLSTATE[42S02]: Table 'lms_alicia.sqlite_master' doesn't exist
 *
 * because the SQLite catalogue query ran whatever the driver was. The comment
 * beside it said an empty result on MySQL would trigger a fallback; the query
 * does not return empty there, it throws, so the fallback was unreachable.
 *
 * The suite still runs on SQLite, so these tests assert the properties that
 * hold on any driver -- above all that the file can rebuild a database from
 * nothing, which the old dump could not: it wrote INSERT statements and no
 * schema, so restoring it required a database that already had every table.
 */
class BackupDumpTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('app/backup-dump-test');
        File::deleteDirectory($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /** Runs the command and returns the SQL it put in the archive. */
    private function dumpSql(): string
    {
        Artisan::call('lms:backup', ['--path' => $this->dir]);

        $zips = collect(File::files($this->dir))->filter(fn ($f) => $f->getExtension() === 'zip');
        $this->assertCount(1, $zips, 'the command wrote no archive');

        $zip = new ZipArchive;
        $zip->open($zips->first()->getRealPath());

        $sql = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (str_ends_with($name, '.sql')) {
                $sql = $zip->getFromIndex($i);
            }
        }
        $zip->close();

        $this->assertNotNull($sql, 'the archive contains no database dump');

        return $sql;
    }

    /**
     * The dump carries the schema, so it can rebuild from nothing.
     *
     * This is the difference between a backup and a list of rows. The old
     * portable dump wrote only INSERTs, which restore into an existing
     * database and are useless after the kind of failure a backup is for.
     */
    public function test_the_dump_contains_the_schema_not_just_the_rows(): void
    {
        $this->seedCore();

        $sql = $this->dumpSql();

        $this->assertStringContainsString('CREATE TABLE', $sql,
            'the dump has no schema, so it cannot restore a database that lost its tables');
        $this->assertStringContainsString('DROP TABLE IF EXISTS', $sql,
            'the dump cannot be applied twice, or over a partly-restored database');
        $this->assertStringContainsString('INSERT INTO', $sql);
    }

    /**
     * Foreign keys are switched off around it.
     *
     * Tables are written in whatever order the server lists them, so a child
     * row will meet its parent's table before the parent has rows. Without
     * this the restore fails part-way and leaves a half-populated database.
     */
    public function test_the_dump_disables_foreign_keys_while_it_loads(): void
    {
        $this->seedCore();

        $sql = $this->dumpSql();

        $this->assertMatchesRegularExpression(
            '/(SET FOREIGN_KEY_CHECKS=0|PRAGMA foreign_keys=OFF)/', $sql,
            'the restore will be rejected by a foreign key before it finishes');
    }

    /**
     * And it actually restores. End to end, on the driver the suite has.
     *
     * Everything above is a property of the text; this is the claim itself.
     */
    public function test_the_dump_restores_into_an_empty_database(): void
    {
        $this->seedCore();
        User::factory()->count(3)->create();

        $expectedUsers = DB::table('users')->count();
        $expectedPerms = DB::table('permissions')->count();

        $sql = $this->dumpSql();

        // A second, genuinely empty SQLite database, restored from the file.
        $fresh = new \PDO('sqlite::memory:');
        $fresh->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        foreach (explode(";\n", $sql) as $statement) {
            $statement = trim($statement);

            if ($statement === '' || str_starts_with($statement, '--')) {
                continue;
            }

            $fresh->exec($statement);
        }

        $this->assertSame($expectedUsers,
            (int) $fresh->query('SELECT COUNT(*) FROM users')->fetchColumn(),
            'the users did not survive the round trip');

        $this->assertSame($expectedPerms,
            (int) $fresh->query('SELECT COUNT(*) FROM permissions')->fetchColumn());
    }

    /**
     * The SQLite catalogue is never consulted unless the driver is SQLite.
     *
     * A source assertion, and a blunt one, but it pins the exact defect: the
     * suite cannot reach the MySQL branch, so without this nothing here
     * notices the query moving back out of its guard.
     */
    public function test_the_sqlite_catalogue_is_only_read_on_sqlite(): void
    {
        $source = file_get_contents(app_path('Console/Commands/BackupSystem.php'));

        // Everything from the sqlite_master query back to the nearest driver
        // test must contain that test -- i.e. the query sits inside it.
        $before = substr($source, 0, (int) strpos($source, 'FROM sqlite_master'));
        $guard = strrpos($before, "driver === 'sqlite'");

        $this->assertNotFalse($guard,
            'the sqlite_master query is not inside a driver check, so it will run on MySQL and throw');
    }

    /**
     * The driver decides, not the connection's name.
     *
     * They are usually the same and do not have to be. A connection named
     * "mysql" pointed at something else is exactly how a MySQL database ended
     * up being asked for its sqlite_master table.
     */
    public function test_the_dump_branches_on_the_driver_not_the_connection_name(): void
    {
        $source = file_get_contents(app_path('Console/Commands/BackupSystem.php'));

        $this->assertStringContainsString('getDriverName()', $source);
        $this->assertStringNotContainsString("config('database.default')", $source,
            'the backup is choosing its dump method from the connection name again');
    }

    /**
     * mysqldump is looked for where XAMPP actually puts it.
     *
     * `mysqldump --version` was the entire search, and on XAMPP that fails --
     * the binary lives in xampp\mysql\bin, which is not on PATH. Every XAMPP
     * install silently took the portable path, which is how a broken portable
     * path went unnoticed.
     */
    public function test_mysqldump_is_looked_for_in_the_xampp_folder(): void
    {
        $source = file_get_contents(app_path('Console/Commands/BackupSystem.php'));

        $this->assertStringContainsString('mysql\\\\bin\\\\mysqldump.exe', $source,
            'mysqldump is only looked for on PATH, where XAMPP does not put it');
        $this->assertStringContainsString('mariadb-dump', $source,
            'only the old mysqldump name is recognised; XAMPP ships MariaDB');
    }

    /**
     * The database password is not put on the command line.
     *
     * Any other account on the machine can read a running process's arguments,
     * so a password passed as --password=... is readable for as long as the
     * dump takes. It goes in a temporary defaults file instead.
     */
    public function test_the_password_is_not_passed_as_an_argument(): void
    {
        $source = file_get_contents(app_path('Console/Commands/BackupSystem.php'));

        $this->assertStringNotContainsString('--password=', $source,
            'the database password is on the command line, where the process list exposes it');
        $this->assertStringContainsString('--defaults-extra-file=', $source);
    }
}
