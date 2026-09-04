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
            'signature' => ['required', 'image', 'mimes:png,jpg,jpeg', 'max:2048', 'dimensions:max_width=2000,max_height=1000'],
        ], [], ['signature' => 'signature image']);

        $user = $request->user();
        $profile = $user->employeeProfile;

        abort_if($profile === null, 403, 'Only an employee record can carry a signature.');

        $file = $request->file('signature');
        $hash = hash_file('sha256', $file->getRealPath());

        // A fresh name each time rather than overwriting in place: the old
        // file is removed explicitly below, and a cached copy of a previous
        // signature can never be served from a reused path.
        $path = $file->store(self::DIR.'/'.$user->id, 'local');

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
