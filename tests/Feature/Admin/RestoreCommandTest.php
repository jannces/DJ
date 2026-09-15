<?php

namespace Tests\Feature\Admin;

use App\Models\Department;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Putting a backup back.
 *
 * The point of these is not that the command runs — it is that an archive
 * taken before a loss still contains the office's records afterwards, which is
 * the only property a backup is ever judged on.
 */
class RestoreCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->directory = storage_path('app/testing-backups');
        File::deleteDirectory($this->directory);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function backup(): string
    {
        $this->artisan('lms:backup', ['--path' => $this->directory])->assertSuccessful();

        $archive = collect(File::files($this->directory))->first(fn ($f) => $f->getExtension() === 'zip');
        $this->assertNotNull($archive, 'lms:backup produced no archive.');

        return $archive->getRealPath();
    }

    public function test_an_archive_brings_back_records_deleted_after_it_was_taken(): void
    {
        $department = Department::create(['name' => 'Municipal Engineering Office', 'code' => 'MEO']);
        User::factory()->create(['email' => 'restore.me@alicia.gov.ph']);

        $archive = $this->backup();

        User::where('email', 'restore.me@alicia.gov.ph')->forceDelete();
        $department->delete();
        $this->assertDatabaseMissing('users', ['email' => 'restore.me@alicia.gov.ph']);

        $this->artisan('lms:restore', ['archive' => $archive, '--force' => true])->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'restore.me@alicia.gov.ph']);
        $this->assertDatabaseHas('departments', ['code' => 'MEO']);
    }

    public function test_settings_survive_the_round_trip(): void
    {
        // system_settings has columns called `key` and `group`; a dump that
        // does not quote identifiers restores as a syntax error.
        SystemSetting::updateOrCreate(
            ['key' => 'general.lgu_name'],
            ['group' => 'general', 'value' => 'MUNICIPALITY OF ALICIA (restored)', 'type' => 'string'],
        );

        $archive = $this->backup();
        SystemSetting::query()->delete();

        $this->artisan('lms:restore', ['archive' => $archive, '--force' => true])->assertSuccessful();

        $this->assertSame('MUNICIPALITY OF ALICIA (restored)', SystemSetting::get('general.lgu_name'));
    }

    public function test_restoring_does_not_duplicate_what_is_already_there(): void
    {
        Department::create(['name' => 'Office of the Mayor', 'code' => 'OM']);
        $archive = $this->backup();

        $this->artisan('lms:restore', ['archive' => $archive, '--force' => true])->assertSuccessful();

        $this->assertSame(1, Department::where('code', 'OM')->count());
    }

    public function test_it_says_which_file_it_could_not_find(): void
    {
        $this->artisan('lms:restore', ['archive' => $this->directory.'/nothing-here.zip', '--force' => true])
            ->expectsOutputToContain('Backup archive not found')
            ->assertFailed();
    }

    public function test_it_asks_before_replacing_the_database(): void
    {
        $archive = $this->backup();

        $this->artisan('lms:restore', ['archive' => $archive])
            ->expectsConfirmation(
                'This REPLACES the current database with the contents of '.basename($archive)
                .'. Everything recorded since that backup will be lost. Continue?',
                'no'
            )
            ->expectsOutputToContain('Nothing was changed')
            ->assertFailed();
    }

    public function test_latest_picks_the_newest_archive_without_naming_it(): void
    {
        File::ensureDirectoryExists(storage_path('app/backups'));
        $before = collect(File::files(storage_path('app/backups')))->map->getRealPath();

        User::factory()->create(['email' => 'newest@alicia.gov.ph']);
        $this->artisan('lms:backup')->assertSuccessful();
        User::where('email', 'newest@alicia.gov.ph')->forceDelete();

        $this->artisan('lms:restore', ['--latest' => true, '--force' => true])->assertSuccessful();
        $this->assertDatabaseHas('users', ['email' => 'newest@alicia.gov.ph']);

        // leave the backups directory as it was found
        foreach (File::files(storage_path('app/backups')) as $file) {
            if (! $before->contains($file->getRealPath())) {
                File::delete($file->getRealPath());
            }
        }
    }

    public function test_it_explains_itself_when_there_is_nothing_to_restore(): void
    {
        $this->artisan('lms:restore', ['--force' => true])
            ->expectsOutputToContain('Name an archive to restore')
            ->assertFailed();
    }
}
