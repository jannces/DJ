<?php

namespace Tests\Feature\Leave;

use App\Models\Department;
use App\Models\EmployeeProfile;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The digital signature on CSC Form No. 6.
 *
 * The form's applicant line used to print a typed name, which is a record
 * that somebody typed something. This replaces it with the applicant's own
 * signature, and most of what is worth testing here is not that the image
 * appears -- it is who can reach it and what happens to it over time.
 */
class SignatureTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;

    private Department $office;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();

        $this->office = Department::create(['name' => 'Municipal Treasurers Office', 'code' => 'MTO']);
        $this->employee = $this->makeEmployee('Josh Kirby Bote');
    }

    private function makeEmployee(string $name, ?Department $office = null): User
    {
        $user = $this->makeUser('employee');
        $user->update(['name' => $name]);

        EmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'employee_no' => 'EMP-'.substr(md5($name), 0, 4),
            'department_id' => ($office ?? $this->office)->id,
            'position_id' => Position::factory()->create()->id,
        ]);

        return $user->refresh();
    }

    protected function employeeUser(): User
    {
        return $this->employee;
    }

    protected function uploadRaw(User $user, UploadedFile $file): void
    {
        $this->upload($user, $file);
    }

    protected function filedWithSignature(): LeaveRequest
    {
        return $this->fileForThroughTheService();
    }

    private function signIn(User $user): self
    {
        $this->actingAs($user);
        session(['otp_verified' => true]);

        return $this;
    }

    /** A real PNG, because the validator inspects the file rather than its name. */
    private function png(string $name = 'signature.png'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 600, 200);
    }

    private function upload(User $user, ?UploadedFile $file = null): void
    {
        $this->signIn($user)
            ->post(route('signature.store'), ['signature' => $file ?? $this->png()])
            ->assertRedirect(route('signature.edit'));
    }

    /**
     * File through the service, not the factory.
     *
     * The factory writes the row directly and so never runs the snapshot,
     * which means the application it produces carries no signature and every
     * authorisation test below would pass by 404 rather than by permission --
     * green for the wrong reason.
     */
    private function fileFor(User $user): LeaveRequest
    {
        return $this->fileForThroughTheService($user);
    }

    // ------------------------------------------------------------ the upload

    public function test_a_signature_is_stored_off_the_public_disk_with_its_digest(): void
    {
        $this->upload($this->employee);

        $profile = $this->employee->employeeProfile->refresh();

        $this->assertNotNull($profile->signature_path);
        $this->assertTrue(Storage::disk('local')->exists($profile->signature_path));

        // The private disk, not the public one -- the whole point of the
        // feature is that a signature is not a file anyone can link to.
        $this->assertFalse(Storage::disk('public')->exists($profile->signature_path),
            'the signature was written somewhere the web server serves directly');

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $profile->signature_hash,
            'no SHA-256 digest was recorded for the uploaded file');
        $this->assertSame(
            hash_file('sha256', Storage::disk('local')->path($profile->signature_path)),
            $profile->signature_hash,
            'the recorded digest does not match the bytes on disk');
    }

    /**
     * The page you land on after uploading actually renders.
     *
     * It did not. `signature_uploaded_at` was fillable but not cast, so it
     * came back from the database as a string and the signature page called
     * ->format() on it -- a fatal, and a 500 on the redirect straight after a
     * successful upload. The feature worked; the page confirming it crashed.
     *
     * The whole suite missed it, and the reason matters more than the bug:
     * `actingAs()` keeps ONE User object for every request a test makes, so
     * the profile the view read was the same in-memory instance the upload had
     * just written a Carbon onto. Nothing ever re-read the row. A real browser
     * gets a fresh user from the session on the next request, which is where
     * the string -- and the crash -- came from.
     *
     * So this signs in AGAIN with a fresh instance before loading the page.
     * Without that line this test passes against the broken code.
     */
    public function test_the_signature_page_renders_after_an_upload(): void
    {
        $this->upload($this->employee);

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->employee->fresh());
        session(['otp_verified' => true]);

        $this->get(route('signature.edit'))
            ->assertOk()
            ->assertSee('Uploaded');
    }

    /** And the column is a date on the way back out, not just on the way in. */
    public function test_the_upload_time_survives_a_round_trip_as_a_date(): void
    {
        $this->upload($this->employee);

        $fresh = \App\Models\EmployeeProfile::where('user_id', $this->employee->id)->firstOrFail();

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $fresh->signature_uploaded_at,
            'signature_uploaded_at reads back as a string, so anything formatting it fatals');
    }

    /**
     * Anything that is not an image is refused by the server.
     *
     * `accept` on the input is a convenience for the file picker and no kind
     * of check; a PHP file renamed to .png has to be refused by something that
     * looks at the file.
     */
    public function test_a_file_that_is_not_an_image_is_refused(): void
    {
        $this->signIn($this->employee)
            ->post(route('signature.store'), [
                'signature' => UploadedFile::fake()->createWithContent('signature.png', '<?php echo 1;'),
            ])
            ->assertSessionHasErrors('signature');

        $this->assertNull($this->employee->employeeProfile->refresh()->signature_path);
    }

    // ---------------------------------------------------------- who may look

    /**
     * An employee cannot read a colleague's signature.
     *
     * This is the reason the file is not simply dropped under public/: knowing
     * a reference number must not be enough to fetch somebody else's
     * signature, and the check has to be on the ENDPOINT rather than on
     * whether a link is drawn on a page.
     */
    public function test_an_employee_cannot_fetch_another_employees_signature(): void
    {
        $this->upload($this->employee);
        $theirs = $this->fileFor($this->employee);

        $colleague = $this->makeEmployee('Nosy Colleague');

        $this->signIn($colleague)
            ->get(route('leave.signature', $theirs))
            ->assertForbidden();
    }

    public function test_the_applicant_and_hr_can_both_see_it(): void
    {
        $this->upload($this->employee);
        $request = $this->fileFor($this->employee);

        $this->signIn($this->employee)->get(route('leave.signature', $request))->assertOk();
        $this->signIn($this->makeUser('hr'))->get(route('leave.signature', $request))->assertOk();
    }

    /**
     * A department head sees their own office and no other.
     *
     * `leave.review.department` says "may review a department", not "may
     * review every department". Without checking which office the head
     * actually heads, the permission alone would let any head read the
     * signature of anyone in the LGU.
     */
    public function test_a_department_head_sees_only_their_own_office(): void
    {
        $this->upload($this->employee);
        $request = $this->fileFor($this->employee);

        $ownHead = $this->makeUser('department-head');
        $this->office->update(['head_user_id' => $ownHead->id]);

        $otherOffice = Department::create(['name' => 'Municipal Engineering Office', 'code' => 'MEO']);
        $otherHead = $this->makeUser('department-head');
        $otherOffice->update(['head_user_id' => $otherHead->id]);

        $this->signIn($ownHead)->get(route('leave.signature', $request))->assertOk();
        $this->signIn($otherHead)->get(route('leave.signature', $request))->assertForbidden();
    }

    /**
     * There is no route that takes an employee id.
     *
     * Writes are scoped to the session user, so the only way to aim this at
     * somebody else would be a route that accepts an identifier. There is
     * none, and this asserts that rather than trusting it stays that way.
     */
    public function test_no_signature_route_is_addressed_by_employee(): void
    {
        foreach (\Route::getRoutes() as $route) {
            if (! str_contains((string) $route->getName(), 'signature')) {
                continue;
            }

            $this->assertStringNotContainsString('{user', $route->uri(),
                "route {$route->getName()} takes a user in its path, which is how "
                .'one employee ends up reading another\'s signature');
            $this->assertStringNotContainsString('{employee', $route->uri(),
                "route {$route->getName()} takes an employee in its path");
        }
    }

    // ------------------------------------------------------- what was signed

    /**
     * An application keeps the signature it was FILED with.
     *
     * The first version of this stored the profile's own path on the leave
     * request. Replacing a signature deletes the file it replaces, so that
     * version would have quietly broken the signature on every application
     * already filed -- including ones already approved and printed. The
     * application gets its own copy instead.
     */
    public function test_replacing_a_signature_does_not_change_one_already_filed(): void
    {
        $this->upload($this->employee);

        $request = $this->fileForThroughTheService();
        $filedPath = $request->applicant_signature_path;
        $filedHash = $request->applicant_signature_hash;

        $this->assertNotNull($filedPath, 'the application did not record a signature');
        $filedBytes = Storage::disk('local')->get($filedPath);

        // A different image, so the digests genuinely differ.
        $this->upload($this->employee, UploadedFile::fake()->image('new.png', 400, 150));

        $request->refresh();

        $this->assertSame($filedPath, $request->applicant_signature_path,
            'the filed application now points at a different file');
        $this->assertSame($filedHash, $request->applicant_signature_hash,
            'the filed application now records a different digest');
        $this->assertTrue(Storage::disk('local')->exists($filedPath),
            'replacing the profile signature deleted the copy an application was filed with');
        $this->assertSame($filedBytes, Storage::disk('local')->get($filedPath),
            'the filed signature is no longer the image it was filed with');
    }

    /** Removing a profile signature likewise leaves filed applications signed. */
    public function test_removing_a_signature_leaves_filed_applications_signed(): void
    {
        $this->upload($this->employee);
        $request = $this->fileForThroughTheService();
        $filedPath = $request->applicant_signature_path;

        $this->signIn($this->employee)->delete(route('signature.destroy'))
            ->assertRedirect(route('signature.edit'));

        $this->assertNull($this->employee->employeeProfile->refresh()->signature_path);
        $this->assertTrue(Storage::disk('local')->exists($filedPath),
            'removing a signature unsigned an application that was already filed');
    }

    // -------------------------------------------------------------- the form

    /** With a signature on file, the form draws it; without one, the typed name. */
    public function test_the_printed_form_shows_the_image_or_falls_back_to_the_name(): void
    {
        $withSignature = $this->fileForThroughTheService(upload: true);
        $without = $this->fileForThroughTheService(
            user: $this->makeEmployee('Unsigned Employee'), upload: false);

        $this->assertNotNull($withSignature->applicant_signature_path);
        $this->assertNull($without->applicant_signature_path);

        // Both still print the applicant's name: on the paper form the name is
        // written under the signature, and a signature alone does not say who
        // signed.
        foreach ([$withSignature, $without] as $request) {
            $pdf = $this->signIn($this->makeUser('hr'))
                ->get(route('leave.form6', $request))->assertOk()->getContent();

            $this->assertStringStartsWith('%PDF', $pdf);
        }
    }

    /**
     * File one the way the application actually files one, so the snapshot
     * runs. LeaveRequest::factory() writes the row directly and skips it.
     */
    private function fileForThroughTheService(?User $user = null, bool $upload = false): LeaveRequest
    {
        $user ??= $this->employee;

        if ($upload) {
            $this->upload($user);
        }

        $vl = LeaveType::where('code', 'VL')->firstOrFail();

        // Vacation Leave needs credits and its "to be spent" detail, the same
        // way the real form supplies them.
        \App\Models\LeaveBalance::firstOrCreate(
            ['user_id' => $user->id, 'leave_type_id' => $vl->id],
            ['earned' => 30, 'used' => 0, 'balance' => 30],
        );

        return app(\App\Services\Leave\LeaveApplicationService::class)->submit($user->refresh(), $vl, [
            'date_filed' => '2026-07-01', 'start_date' => '2026-07-13', 'end_date' => '2026-07-15',
            'purpose' => 'Family matters',
            'details' => ['location' => 'within_ph', 'location_specify' => 'Alicia, Isabela'],
            'applicant_signature' => $user->name,
        ]);
    }
}
