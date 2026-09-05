<?php

namespace App\Http\Controllers;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Security\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A person's signature: uploaded once, kept private, printed on their form.
 *
 * Uploaded rather than drawn. A finger-drawn scrawl on a phone does not look
 * like the signature on the rest of somebody's employment file, and a canvas
 * pad would be the only part of this system that stops working with scripts
 * off. What an LGU actually has is a signature on paper, so this takes a photo
 * or a scan of one.
 *
 * Everything here is scoped to the SIGNED-IN user. There is no route that
 * takes an employee id and no form field that carries one: `$request->user()`
 * is the only way an account is identified for a write, so no employee can
 * upload, replace or delete a signature that is not their own by editing a
 * form, a URL or a hidden input.
 */
class SignatureController extends Controller
{
    /** Where a signature lives on the private disk. Never under public/. */
    private const DIR = 'signatures';

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function edit(Request $request): View
    {
        return view('profile.signature', [
            'profile' => $request->user()->employeeProfile,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        // Server-side, and not merely an `accept` attribute on the input.
        // `image` makes PHP look at the file rather than trust its name, so a
        // renamed .php does not get through on its extension; mimes narrows it
        // to the three raster formats a scanner or a phone produces.
        $request->validate([
            'signature' => [
                'required', 'image', 'mimes:png,jpg,jpeg',
                // 8MB and 8000px: a bound against something absurd, not a
                // usability limit. It used to be 2MB and 2000x1000, which
                // refused the exact file this page asks for -- a phone photo
                // of a signed sheet is 4032x3024 and three to six megabytes,
                // and a 300dpi scan is bigger still. Both came back as
                // "invalid image dimensions" while the instructions beside
                // the button said to photograph or scan it.
                'max:8192',
                // A floor as well, which there never was. Something 80px wide
                // prints as a smudge on the form, and it is better to say so
                // at the upload than to let somebody discover it on paper.
                'dimensions:min_width=200,min_height=60,max_width=8000,max_height=8000',
            ],
        ], [], ['signature' => 'signature image']);

        $user = $request->user();
        $profile = $user->employeeProfile;

        abort_if($profile === null, 403, 'Only an employee record can carry a signature.');

        // A fresh name each time rather than overwriting in place: the old
        // file is removed explicitly below, and a cached copy of a previous
        // signature can never be served from a reused path.
        $path = $request->file('signature')->store(self::DIR.'/'.$user->id, 'local');

        // Shrink and straighten before anything records what is on disk.
        //
        // A phone photo is twelve megapixels and several megabytes, and every
        // byte of it would be embedded in every PDF this employee ever files
        // -- to be drawn 26pt tall. It is also, half the time, sideways: the
        // camera writes the rotation into EXIF rather than into the pixels,
        // and dompdf does not read EXIF.
        $this->normalise(Storage::disk('local')->path($path));

        // Hashed AFTER, so the digest identifies the bytes actually stored
        // rather than the ones that were posted.
        $hash = hash_file('sha256', Storage::disk('local')->path($path));

        $previous = $profile->signature_path;

        $profile->update([
            'signature_path' => $path,
            'signature_hash' => $hash,
            'signature_uploaded_at' => now(),
        ]);

        // The file is deleted only after the record points somewhere else, so
        // an interrupted write leaves the old signature readable rather than
        // leaving the profile pointing at nothing.
        if ($previous && $previous !== $path) {
            Storage::disk('local')->delete($previous);
        }

        // The image is not logged, and neither is its name -- only that a
        // signature was set, and the digest that identifies which one.
        $this->audit->log('signature_updated', $profile, [], ['sha256' => $hash], $user);

        return redirect()->route('signature.edit')
            ->with('status', 'Your signature has been saved. Applications you file from now on will carry it.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();
        $profile = $user->employeeProfile;

        abort_if($profile === null, 403);

        if ($profile->signature_path) {
            Storage::disk('local')->delete($profile->signature_path);
        }

        $profile->update([
            'signature_path' => null,
            'signature_hash' => null,
            'signature_uploaded_at' => null,
        ]);

        $this->audit->log('signature_removed', $profile, [], [], $user);

        // Applications already filed keep their own copy and are untouched:
        // the snapshot on the leave request is a separate file reference, and
        // removing the profile's signature does not unsign what was signed.
        return redirect()->route('signature.edit')
            ->with('status', 'Your signature has been removed. Applications already filed keep the signature they were filed with.');
    }

    /**
     * Your own signature, as it currently stands on your profile.
     *
     * Separate from show() below, and deliberately not the same file: this one
     * is the profile's current signature, that one is the copy a particular
     * application was filed with. They differ the moment somebody replaces
     * theirs, which is exactly the case this feature exists to get right.
     *
     * No id anywhere -- the account comes from the session, so this route
     * cannot be pointed at anybody else.
     */
    public function mine(Request $request): StreamedResponse
    {
        $path = $request->user()->employeeProfile?->signature_path;
        abort_if($path === null || ! Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, 'signature.png', [
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * The signature on one application, for the people entitled to see it.
     *
     * Addressed by the LEAVE REQUEST, not by the employee: "show me the
     * signature on application LV-2026-00123" is a question the workflow can
     * answer, and it is answered with the same authorisation that decides who
     * may open the application at all. "Show me employee 14's signature" is
     * not a question this system needs to be asked, so there is no route that
     * asks it.
     */
    public function show(Request $request, LeaveRequest $leaveRequest): StreamedResponse
    {
        $this->authorizeSignature($request->user(), $leaveRequest);

        $path = $leaveRequest->applicant_signature_path;
        abort_if($path === null || ! Storage::disk('local')->exists($path), 404);

        // Inline, because this is drawn into the form rather than saved.
        return Storage::disk('local')->response($path, 'signature.png', [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * The longest edge a stored signature is allowed to keep.
     *
     * The printed form draws it at most 26pt tall and 150pt wide, so 1600px
     * is already far more detail than any paper copy can show. What it buys
     * is a file measured in tens of kilobytes rather than megabytes, in a PDF
     * an office downloads over a LAN.
     */
    private const MAX_EDGE = 1600;

    /**
     * Turn whatever was uploaded into something fit to print, in place.
     *
     * Best-effort by design: every failure path leaves the original file
     * exactly as it was. A signature that is merely larger than ideal is a
     * working signature, and losing somebody's upload because GD would not
     * open it is a worse outcome than storing four megabytes.
     */
    private function normalise(string $absolute): void
    {
        $info = @getimagesize($absolute);

        if ($info === false || ! function_exists('imagecreatetruecolor')) {
            return;
        }

        [$width, $height] = $info;
        $type = $info[2];
        $orientation = $this->orientation($absolute, $type);

        // GD holds the bitmap uncompressed at four bytes a pixel, and briefly
        // holds source and destination at once. Skip rather than exhaust the
        // process: an 8000x8000 upload is 256MB before anything is drawn.
        if (! $this->canAfford($width * $height + self::MAX_EDGE ** 2)) {
            return;
        }

        $source = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($absolute),
            IMAGETYPE_PNG => @imagecreatefrompng($absolute),
            default => false,
        };

        if ($source === false) {
            return;
        }

        // Rotate first, so the scale is worked out against the edges the
        // reader will actually see.
        $source = $this->applyOrientation($source, $orientation);

        // Then throw the paper away and keep the ink.
        //
        // This is why a signature printed so small. What people upload is a
        // photograph of a whole sheet of A4 with a signature somewhere in the
        // middle of it, so the form was told to fit THE SHEET into 26pt --
        // and the ink, a fifth of that frame, came out about five points
        // tall. Cropping to the writing means the height the form gives it is
        // spent entirely on the signature.
        $source = $this->trim($source);

        $width = imagesx($source);
        $height = imagesy($source);

        $scale = min(1, self::MAX_EDGE / max($width, $height));
        $target = (int) round($width * $scale);
        $targetH = (int) round($height * $scale);

        $out = imagecreatetruecolor($target, $targetH);

        // Ink on paper is usually a PNG with a transparent background. Without
        // these two lines the transparency is filled with black, and the form
        // prints a black rectangle where the signature should be.
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 255, 255, 255, 127));

        imagecopyresampled($out, $source, 0, 0, 0, 0, $target, $targetH, $width, $height);

        $type === IMAGETYPE_PNG
            ? @imagepng($out, $absolute, 6)
            : @imagejpeg($out, $absolute, 85);

        imagedestroy($source);
        imagedestroy($out);
    }

    /**
     * Crop the blank margin off a photographed or scanned signature.
     *
     * The bounding box is found on a small copy rather than the full image:
     * reading twelve million pixels one at a time in PHP takes seconds, and
     * 300px across is ample for locating where the writing starts. The box is
     * then scaled back up and the crop taken from the original, so nothing is
     * resampled twice.
     *
     * "Ink" is anything meaningfully darker than paper, or -- on a PNG saved
     * with the background knocked out -- anything not transparent. A photo of
     * white paper is never pure white, so the threshold sits well below it and
     * a margin of the shorter edge is left around the writing, because a
     * signature cropped flush to its own strokes looks cramped on the form.
     *
     * @param  \GdImage  $image
     * @return \GdImage
     */
    private function trim($image)
    {
        $width = imagesx($image);
        $height = imagesy($image);

        $scale = min(1, 300 / max($width, $height));
        $sw = max(1, (int) round($width * $scale));
        $sh = max(1, (int) round($height * $scale));

        $small = imagecreatetruecolor($sw, $sh);
        imagealphablending($small, false);
        imagesavealpha($small, true);
        imagecopyresampled($small, $image, 0, 0, 0, 0, $sw, $sh, $width, $height);

        $left = $sw;
        $top = $sh;
        $right = -1;
        $bottom = -1;

        for ($y = 0; $y < $sh; $y++) {
            for ($x = 0; $x < $sw; $x++) {
                $rgba = imagecolorat($small, $x, $y);
                $alpha = ($rgba >> 24) & 0x7F;

                if ($alpha > 100) {
                    continue; // Transparent: background, not writing.
                }

                $luma = (0.299 * (($rgba >> 16) & 0xFF))
                    + (0.587 * (($rgba >> 8) & 0xFF))
                    + (0.114 * ($rgba & 0xFF));

                if ($luma < 200) {
                    $left = min($left, $x);
                    $top = min($top, $y);
                    $right = max($right, $x);
                    $bottom = max($bottom, $y);
                }
            }
        }

        imagedestroy($small);

        // Nothing found, or ink everywhere: either way there is no margin to
        // remove, and a crop would be a guess.
        if ($right < $left || $bottom < $top) {
            return $image;
        }

        $box = [
            'x' => (int) floor($left / $scale),
            'y' => (int) floor($top / $scale),
            'width' => (int) ceil(($right - $left + 1) / $scale),
            'height' => (int) ceil(($bottom - $top + 1) / $scale),
        ];

        if ($box['width'] >= $width * 0.98 && $box['height'] >= $height * 0.98) {
            return $image;
        }

        $pad = (int) round(min($box['width'], $box['height']) * 0.08);
        $box['x'] = max(0, $box['x'] - $pad);
        $box['y'] = max(0, $box['y'] - $pad);
        $box['width'] = min($width - $box['x'], $box['width'] + $pad * 2);
        $box['height'] = min($height - $box['y'], $box['height'] + $pad * 2);

        $cropped = @imagecrop($image, $box);

        if ($cropped === false) {
            return $image;
        }

        imagedestroy($image);

        return $cropped;
    }

    /** The EXIF rotation a phone wrote, or 1 when there is nothing to do. */
    private function orientation(string $absolute, int $type): int
    {
        if ($type !== IMAGETYPE_JPEG || ! function_exists('exif_read_data')) {
            return 1;
        }

        $exif = @exif_read_data($absolute);

        return (int) ($exif['Orientation'] ?? 1) ?: 1;
    }

    /** @param \GdImage $image */
    private function applyOrientation($image, int $orientation)
    {
        $degrees = match ($orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($degrees === 0) {
            return $image;
        }

        $rotated = @imagerotate($image, $degrees, 0);

        if ($rotated === false) {
            return $image;
        }

        imagedestroy($image);

        return $rotated;
    }

    /** Whether GD can hold this many pixels within what PHP is allowed. */
    private function canAfford(int $pixels): bool
    {
        $limit = trim((string) ini_get('memory_limit'));

        if ($limit === '' || $limit === '-1') {
            return true;
        }

        $bytes = (int) $limit;
        $bytes *= match (strtolower(substr($limit, -1))) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };

        // Four bytes a pixel, and leave the request itself room to finish.
        return $pixels * 4 < ($bytes - memory_get_usage(true)) * 0.8;
    }

    /**
     * Who may look at a signature.
     *
     * The applicant, and the officers who handle the application: HR, who
     * certifies and decides it, and the head of the applicant's own office,
     * who is asked to recommend it. Nobody else -- an employee cannot read a
     * colleague's signature by knowing their reference number, which is the
     * whole reason this is not simply a file under public/.
     */
    private function authorizeSignature(User $user, LeaveRequest $leaveRequest): void
    {
        if ($user->id === $leaveRequest->user_id) {
            return;
        }

        // HR certifies and decides every application, so HR sees every
        // signature.
        foreach (['leave.certify.hr', 'leave.approve.final'] as $permission) {
            if ($user->hasPermission($permission)) {
                return;
            }
        }

        // A department head sees only their OWN office. The permission alone
        // is not enough: `leave.review.department` says "may review a
        // department", and which department that is comes from who heads it,
        // not from who is asking. Without this check any head could read the
        // signature of anyone in the LGU by opening the right reference.
        if ($user->hasPermission('leave.review.department')) {
            $officeId = $leaveRequest->user?->employeeProfile?->department_id;

            if ($officeId !== null && $user->headsDepartments()->whereKey($officeId)->exists()) {
                return;
            }
        }

        abort(403);
    }
}
