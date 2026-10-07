<?php

namespace Tests\Feature\Admin;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

/**
 * What the backup does when ONE table cannot be read.
 *
 * From a real install, during update.bat:
 *
 *   [1/7] Backing up the database...
 *   Dumping database...
 *          mysqldump failed; using the portable dump instead.
 *   The dump failed: SQLSTATE[42S02]: Base table or view not found: 1932
 *   Table 'lms_alicia.activity_logs' doesn't exist in engine
 *
 *   [X] The backup failed, so the update stops here.
 *
 * Stopping was right. Writing nothing was not. InnoDB had lost the tablespace
 * for activity_logs -- an audit LOG table -- and every leave request, employee
 * record and uploaded document in that database was healthy, readable, and
 * saved nowhere, because the backup was all or nothing. The moment a database
 * starts to break is the moment you most want whatever of it still reads.
 *
 * So now: skip the table, write the archive from the rest, name the file
 * lms_partial_*, put a READ-ME-FIRST.txt in it, and still exit non-zero so
 * update.bat still refuses to migrate.
 *
 * DELIBERATELY WITHOUT RefreshDatabase, following BackupDatabaseDownTest: these
 * tests damage a database on purpose, and doing that to the connection the rest
 * of the suite runs its transactions on breaks every test that follows.
 */
class BackupDamagedTableTest extends TestCase
{
    private string $dir;

    private string $dbPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = storage_path('app/backup-damaged-test');
        $this->dbPath = storage_path('app/backup-damaged-test.sqlite');

        File::deleteDirectory($this->dir);
        File::delete($this->dbPath);
    }

    protected function tearDown(): void
    {
        DB::purge('damaged');
        File::deleteDirectory($this->dir);
        File::delete($this->dbPath);
        parent::tearDown();
    }

    /**
     * A database with two good tables and one the catalogue lists but cannot
     * open -- SQLite's version of MySQL error 1932.
     *
     * The ghost row goes straight into sqlite_master with writable_schema, so
     * the table is named in the catalogue with a rootpage that points nowhere.
     * Reading it then throws while its neighbours stay perfectly readable,
     * which is precisely the shape of the failure on the LGU's machine: the
     * dictionary still knows the name, the storage engine has lost the file.
     */
    private function damagedDatabase(): void
    {
        touch($this->dbPath);

        config([
            'database.connections.damaged' => [
                'driver' => 'sqlite',
                'database' => $this->dbPath,
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
            'database.default' => 'damaged',
        ]);
        DB::purge('damaged');

        $pdo = DB::connection('damaged')->getPdo();
        $pdo->exec('CREATE TABLE employees (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec("INSERT INTO employees (name) VALUES ('Maria Santos'), ('Jose Cruz')");
        $pdo->exec('CREATE TABLE leave_requests (id INTEGER PRIMARY KEY, employee_id INTEGER, days REAL)');
        $pdo->exec('INSERT INTO leave_requests (employee_id, days) VALUES (1, 3.0), (2, 1.5)');

        $pdo->exec('PRAGMA writable_schema=ON');
        $pdo->exec("INSERT INTO sqlite_master (type, name, tbl_name, rootpage, sql)
                    VALUES ('table', 'activity_logs', 'activity_logs', 0,
                            'CREATE TABLE activity_logs (id INTEGER, note TEXT)')");
        $pdo->exec('PRAGMA writable_schema=OFF');
        $pdo->exec('PRAGMA schema_version = 99');
        DB::purge('damaged');
    }

    /** The archive the command wrote, or null. */
    private function archive(): ?\SplFileInfo
    {
        if (! File::isDirectory($this->dir)) {
            return null;
        }

        return collect(File::files($this->dir))
            ->first(fn ($f) => $f->getExtension() === 'zip');
    }

    /** @return array<string, string> file name inside the archive => contents */
    private function contents(\SplFileInfo $file): array
    {
        $zip = new ZipArchive;
        $zip->open($file->getRealPath());

        $out = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $out[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
        }
        $zip->close();

        return $out;
    }

    /**
     * The healthy tables are saved. This is the whole point.
     *
     * One broken log table used to cost the operator every row in the database.
     */
    public function test_the_tables_that_can_be_read_are_still_backed_up(): void
    {
        $this->damagedDatabase();

        Artisan::call('lms:backup', ['--path' => $this->dir]);

        $archive = $this->archive();
        $this->assertNotNull($archive, 'one damaged table stopped the whole backup again');

        $sql = collect($this->contents($archive))
            ->first(fn ($body, $name) => str_ends_with((string) $name, '.sql'));

        $this->assertStringContainsString('CREATE TABLE employees', $sql);
        $this->assertStringContainsString('Maria Santos', $sql, 'the healthy rows were not saved');
        $this->assertStringContainsString('CREATE TABLE leave_requests', $sql);
        $this->assertStringContainsString('Jose Cruz', $sql);
    }

    /**
     * And it is named so nobody mistakes it for a whole one.
     *
     * Whoever reaches for this file is restoring a system that is already
     * broken, probably in a hurry. The filename has to say it first.
     */
    public function test_a_partial_archive_is_named_partial(): void
    {
        $this->damagedDatabase();

        Artisan::call('lms:backup', ['--path' => $this->dir]);

        $this->assertMatchesRegularExpression(
            '/^lms_partial_\d{8}_\d{6}\.zip$/', $this->archive()->getFilename(),
            'an incomplete backup is named exactly like a complete one');
    }

    /** With a note inside naming the tables that are missing from it. */
    public function test_the_archive_explains_what_is_missing(): void
    {
        $this->damagedDatabase();

        Artisan::call('lms:backup', ['--path' => $this->dir]);

        $files = $this->contents($this->archive());

        $this->assertArrayHasKey('READ-ME-FIRST.txt', $files);
        $this->assertStringContainsString('INCOMPLETE', $files['READ-ME-FIRST.txt']);
        $this->assertStringContainsString('activity_logs', $files['READ-ME-FIRST.txt'],
            'the note does not say which table is missing');
    }

    /**
     * The dump itself says so too, for anyone who restores it unopened.
     *
     * The list is at the END of the file rather than only the top, because a
     * table whose rows run out part-way is not known to have failed until it
     * has already been written -- so the top cannot name every skip and the
     * bottom can.
     */
    public function test_the_dump_carries_the_warning(): void
    {
        $this->damagedDatabase();

        Artisan::call('lms:backup', ['--path' => $this->dir]);

        $sql = collect($this->contents($this->archive()))
            ->first(fn ($body, $name) => str_ends_with((string) $name, '.sql'));

        $this->assertStringContainsString('THIS DUMP IS INCOMPLETE', $sql);
        $this->assertStringContainsString('WARNING: activity_logs', $sql);
    }

    /**
     * And the run still fails.
     *
     * update.bat reads this exit code and stops the update on a non-zero. A
     * partial backup is not a safety net, and the moment it starts reporting
     * success the script will happily migrate a damaged database.
     */
    public function test_a_partial_backup_still_reports_failure(): void
    {
        $this->damagedDatabase();

        $code = Artisan::call('lms:backup', ['--path' => $this->dir]);

        $this->assertSame(1, $code,
            'an incomplete backup reported success; update.bat would now migrate over damage');
    }

    /**
     * The last line carries the whole meaning, because that is all that is shown.
     *
     * BackupController puts the command's final line on the Backups page and
     * update.bat prints it in the terminal. It has to say three things at once:
     * something was saved, something was not, and do not go further.
     */
    public function test_the_last_line_says_what_was_saved_and_what_was_not(): void
    {
        $this->damagedDatabase();

        Artisan::call('lms:backup', ['--path' => $this->dir]);

        $last = trim((string) collect(explode("\n", trim(Artisan::output())))->last());

        $this->assertStringContainsString('INCOMPLETE', $last);
        $this->assertStringContainsString('activity_logs', $last, 'the line does not name the damage');
        $this->assertStringContainsString('Everything else was saved', $last);
        $this->assertStringContainsString('Do NOT update', $last, 'the line does not say to stop');
    }

    /**
     * A healthy database is untouched by any of this.
     *
     * The ordinary name, no note, and exit 0 -- the regression that would
     * matter most, since every successful backup takes this path.
     */
    public function test_a_healthy_database_still_produces_a_plain_archive(): void
    {
        $this->damagedDatabase();

        // Remove the ghost row again: same connection, same tables, no damage.
        $pdo = DB::connection('damaged')->getPdo();
        $pdo->exec('PRAGMA writable_schema=ON');
        $pdo->exec("DELETE FROM sqlite_master WHERE name = 'activity_logs'");
        $pdo->exec('PRAGMA writable_schema=OFF');
        $pdo->exec('PRAGMA schema_version = 100');
        DB::purge('damaged');

        $code = Artisan::call('lms:backup', ['--path' => $this->dir]);

        $this->assertSame(0, $code, Artisan::output());
        $this->assertMatchesRegularExpression('/^lms_\d{8}_\d{6}\.zip$/',
            $this->archive()->getFilename());
        $this->assertArrayNotHasKey('READ-ME-FIRST.txt', $this->contents($this->archive()));
    }

    /**
     * lms:db-check --tables names the damage before the backup is even run.
     *
     * The plain check said "Database reachable" and it was telling the truth --
     * the server answered, the connection worked, and one table was gone. That
     * question and "can every table be read?" are not the same question, and
     * after a failed backup the second one is the one being asked.
     */
    public function test_db_check_with_tables_finds_the_damaged_table(): void
    {
        $this->damagedDatabase();

        $code = Artisan::call('lms:db-check', ['--tables' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $code, 'the scan passed a database with an unreadable table');
        $this->assertStringContainsString('activity_logs', $output);
        $this->assertStringContainsString('UNREADABLE', $output);
        $this->assertStringContainsString('Take a backup now', $output,
            'the scan does not say to save what is left');
    }

    /**
     * The plain check still passes, on purpose.
     *
     * update.bat runs `lms:db-check` BEFORE it backs up. If the plain check
     * started failing on a damaged table, the script would stop before taking
     * the backup -- losing exactly the copy this whole change exists to make.
     */
    public function test_the_plain_db_check_does_not_fail_on_a_damaged_table(): void
    {
        $this->damagedDatabase();

        $this->assertSame(0, Artisan::call('lms:db-check'),
            'the pre-flight check now stops update.bat before it can take a backup');
    }
}
