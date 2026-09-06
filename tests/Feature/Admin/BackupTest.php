<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Backups from the browser.
 *
 * The archive is a full database dump plus every uploaded document, which
 * makes it the most sensitive artefact this system produces. These tests are
 * mostly about who may touch it and what cannot be asked for, rather than
 * about zip files.
 */
class BackupTest extends TestCase
{
    use RefreshDatabase;

    private function dir(): string
    {
        return storage_path('app/backups');
    }

    private function seedBackupFile(string $name = 'lms_20260101_120000.zip'): string
    {
        File::ensureDirectoryExists($this->dir());
        $path = $this->dir().'/'.$name;
        File::put($path, 'not really a zip');

        return $path;
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir());
        parent::tearDown();
    }

    public function test_the_system_administrator_can_open_the_page(): void
    {
        $this->seedCore();
        $this->actingAs($this->makeUser('system-admin'));
        session(['otp_verified' => true]);

        $this->get(route('backups.index'))->assertOk()->assertSee('Create backup now');
    }

    /**
     * And nobody else can, including HR.
     *
     * HR runs the leave system; a copy of the entire database is a different
     * thing from the records HR is entitled to read one at a time.
     */
    public function test_other_roles_are_refused(): void
    {
        $this->seedCore();

        foreach (['employee', 'hr', 'department-head', 'mayor'] as $role) {
            $this->actingAs($this->makeUser($role));
            session(['otp_verified' => true]);

            $this->get(route('backups.index'))->assertForbidden();
            $this->post(route('backups.store'))->assertForbidden();
        }
    }

    /** Existing archives are listed, newest first. */
    public function test_existing_backups_are_listed(): void
    {
        $this->seedCore();
        $this->seedBackupFile('lms_20260101_120000.zip');
        $this->actingAs($this->makeUser('system-admin'));
        session(['otp_verified' => true]);

        $this->get(route('backups.index'))
            ->assertOk()
            ->assertSee('lms_20260101_120000.zip');
    }

    /**
     * A name that is not shaped like a backup never reaches the filesystem.
     *
     * The route constraint stops it first and the controller checks again;
     * this asserts the outcome rather than which of the two did it, because
     * either alone would be a thing to lean on.
     */
    public function test_the_download_name_cannot_be_used_to_reach_other_files(): void
    {
        $this->seedCore();
        $this->actingAs($this->makeUser('system-admin'));
        session(['otp_verified' => true]);

        foreach ([
            '../.env',
            '..%2F..%2F.env',
            'lms_20260101_120000.zip/../../.env',
            '.env',
            'anything.zip',
        ] as $name) {
            // 404 from the route constraint, or 400 where the encoded
            // traversal is rejected before routing. Asserting the outcome
            // rather than which layer refused it: what matters is that no
            // request in this list returns a file.
            $status = $this->get('/admin/backups/'.$name)->getStatusCode();

            $this->assertContains($status, [400, 404],
                "'{$name}' was not refused - it returned {$status}");
        }
    }

    /** A well-formed name for a file that is not there is still a 404. */
    public function test_a_missing_backup_is_not_found(): void
    {
        $this->seedCore();
        $this->actingAs($this->makeUser('system-admin'));
        session(['otp_verified' => true]);

        $this->get(route('backups.download', 'lms_20990101_000000.zip'))->assertNotFound();
    }

    /** A real one downloads, and the fact is recorded. */
    public function test_downloading_is_audited(): void
    {
        $this->seedCore();
        $this->seedBackupFile();
        $admin = $this->makeUser('system-admin');
        $this->actingAs($admin);
        session(['otp_verified' => true]);

        $this->get(route('backups.download', 'lms_20260101_120000.zip'))->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'backup_downloaded',
        ]);
    }

    /** Creating one is recorded too, with the file it produced. */
    public function test_creating_a_backup_is_audited(): void
    {
        $this->seedCore();
        $admin = $this->makeUser('system-admin');
        $this->actingAs($admin);
        session(['otp_verified' => true]);

        $this->post(route('backups.store'))->assertRedirect();

        $entry = AuditLog::where('action', 'backup_created')->first();

        $this->assertNotNull($entry, 'creating a backup left no audit entry');
        $this->assertSame($admin->id, $entry->user_id);
    }

    /** The download route requires a signed-in administrator, not just a URL. */
    public function test_a_guest_cannot_download_a_backup(): void
    {
        $this->seedCore();
        $this->seedBackupFile();

        $this->get(route('backups.download', 'lms_20260101_120000.zip'))
            ->assertRedirect(route('login'));
    }
}
