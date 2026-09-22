<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Security\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Backups, from the browser.
 *
 * `php artisan lms:backup` already did this and still does; it is what the
 * scheduled task runs. What was missing was a way for the System Administrator
 * to take one before a risky change without opening a terminal, and any sight
 * of whether the scheduled ones are actually happening.
 *
 * The archive holds a full database dump and every uploaded document, so it is
 * the most sensitive artefact this system produces. Three things follow, and
 * they are the reason this controller is longer than "call the command":
 *
 *   - It lives in storage/app/backups, outside the document root. Nothing is
 *     served by Apache; every download passes through this controller and its
 *     permission check.
 *   - Filenames from the URL are never trusted. The name is matched against
 *     the pattern the command produces and then looked up among the files that
 *     actually exist, so "../../.env" cannot be asked for.
 *   - Creating and downloading are both audited. A copy of the entire database
 *     leaving the building is exactly the event an audit trail exists for.
 */
class BackupController extends Controller
{
    /**
     * What lms:backup names its archives: lms_20260906_141230.zip, or
     * lms_partial_20260906_141230.zip when a table could not be read.
     *
     * A partial archive has to be listed and downloadable -- it is written
     * precisely when the database is failing, so it is the copy that matters
     * most -- but it must never be mistaken for a whole one. Hence the name,
     * and the badge the listing puts beside it.
     */
    private const NAME = '/^lms_(partial_)?\d{8}_\d{6}\.zip$/';

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        return view('admin.backups.index', [
            'backups' => $this->existing(),
            'directory' => $this->directory(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        // Artisan::call rather than a queued job: the administrator is waiting
        // on this page for a result, and a backup that quietly failed in a
        // worker they cannot see is worse than one that took a moment.
        try {
            $code = Artisan::call('lms:backup');
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors(['backup' => 'The backup failed: '.$e->getMessage()]);
        }

        if ($code !== 0) {
            // The command's own last line, not "check the logs". The one real
            // failure this page has seen said exactly which table was missing,
            // and that sentence was the whole diagnosis.
            $said = trim((string) Artisan::output());
            $said = $said === '' ? '' : ' '.trim((string) collect(explode("\n", $said))->last());

            return back()->withErrors(['backup' => 'The backup did not complete.'.$said]);
        }

        $latest = $this->existing()->first();

        $this->audit->log('backup_created', null, [], [
            'file' => $latest['name'] ?? null,
            'size' => $latest['size'] ?? null,
        ]);

        return back()->with('status', 'Backup created'.($latest ? ': '.$latest['name'] : '.'));
    }

    public function download(string $file): BinaryFileResponse
    {
        // Two gates, not one. The pattern rejects anything that is not shaped
        // like a backup name -- traversal included, since '/' and '.' cannot
        // appear -- and the lookup then requires the name to be one this
        // directory actually holds. Neither alone is enough to lean on.
        abort_unless(preg_match(self::NAME, $file) === 1, 404);

        $path = $this->directory().DIRECTORY_SEPARATOR.$file;
        abort_unless(is_file($path), 404);

        $this->audit->log('backup_downloaded', null, [], ['file' => $file]);

        return response()->download($path);
    }

    private function directory(): string
    {
        return storage_path('app/backups');
    }

    /** @return \Illuminate\Support\Collection<int, array{name: string, size: int, at: \Illuminate\Support\Carbon, partial: bool}> */
    private function existing(): \Illuminate\Support\Collection
    {
        $dir = $this->directory();

        if (! File::isDirectory($dir)) {
            return collect();
        }

        return collect(File::files($dir))
            ->filter(fn ($f) => preg_match(self::NAME, $f->getFilename()) === 1)
            ->map(fn ($f) => [
                'name' => $f->getFilename(),
                'size' => $f->getSize(),
                'at' => \Illuminate\Support\Carbon::createFromTimestamp($f->getMTime()),
                'partial' => str_starts_with($f->getFilename(), 'lms_partial_'),
            ])
            ->sortByDesc('at')
            ->values();
    }
}
