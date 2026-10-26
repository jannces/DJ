<?php

namespace Tests\Feature\Leave;

use App\Models\Department;
use App\Models\EmployeeProfile;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CSC Form 6 comes out on the paper you asked for.
 *
 * It used to be fixed to Legal, so every download was a 14-inch page whatever
 * was in the tray -- printers then either shrank it until the legal citations
 * were unreadable or clipped the footer off. The sheet itself was never too
 * big: it holds one page on all four sizes, which is what makes offering the
 * choice safe.
 */
class PaperSizeTest extends TestCase
{
    use RefreshDatabase;

    /** Media box dimensions in points, as dompdf writes them. */
    private const EXPECTED = [
        'legal' => [612, 1008],
        'folio' => [612, 936],
        'a4' => [595, 842],
        'letter' => [612, 792],
    ];

    /** Long bond: what the LGU files this form on, so what it defaults to. */
    private const DEFAULT_PAPER = 'legal';

    protected LeaveRequest $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();

        $office = Department::create(['name' => 'Municipal Treasurers Office', 'code' => 'MTO']);
        $employee = $this->makeUser('employee');
        $employee->update(['name' => 'Josh Kirby B. Bote']);
        EmployeeProfile::factory()->create([
            'user_id' => $employee->id, 'employee_no' => 'EMP-0001',
            'first_name' => 'Josh Kirby', 'last_name' => 'Bote',
            'department_id' => $office->id, 'position_id' => Position::factory()->create()->id,
        ]);

        $this->request = LeaveRequest::factory()->create([
            'user_id' => $employee->id,
            'leave_type_id' => LeaveType::where('code', 'VL')->firstOrFail()->id,
            'status' => 'approved', 'working_days' => 3,
            'date_filed' => now()->subDays(9)->toDateString(),
            'start_date' => now()->subDays(3)->toDateString(),
            'end_date' => now()->subDay()->toDateString(),
            'applicant_signature' => 'Josh Kirby B. Bote',
            'decided_at' => now()->subDays(5),
        ]);
    }

    /**
     * The same request, but signed with an actual IMAGE.
     *
     * The fixture above sets `applicant_signature`, which is the typed name --
     * so every size check in this file ran against a sheet with no signature
     * drawn on it. That mattered the moment the printed signature was allowed
     * to grow: it is capped at 44pt now, up from 26, and 44pt of picture that
     * nothing measured is 44pt that could push the sheet onto a second page.
     */
    private function signIt(): void
    {
        $canvas = imagecreatetruecolor(1200, 300);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagesetthickness($canvas, 10);
        imageline($canvas, 60, 220, 1140, 90, imagecolorallocate($canvas, 10, 10, 40));

        $file = tempnam(sys_get_temp_dir(), 'sig').'.png';
        imagepng($canvas, $file);
        imagedestroy($canvas);

        $applicant = $this->request->user;
        $this->actingAs($applicant);
        session(['otp_verified' => true]);

        $this->post(route('signature.store'), [
            'signature' => new \Illuminate\Http\UploadedFile($file, 'sig.png', 'image/png', null, true),
        ])->assertRedirect();

        $this->request->update([
            'applicant_signature_path' => $applicant->employeeProfile->refresh()->signature_path,
        ]);
    }

    /** One page on every size, with a signature image actually on the sheet. */
    public function test_a_signed_sheet_still_fits_one_page_on_every_size(): void
    {
        $this->signIt();

        foreach (array_keys(self::EXPECTED) as $paper) {
            $pdf = $this->download($paper);

            $this->assertSame(1,
                substr_count($pdf, '/Type /Page') - substr_count($pdf, '/Type /Pages'),
                "the signed sheet runs onto a second page on {$paper}");
        }
    }

    protected function get6(?string $paper): \Illuminate\Testing\TestResponse
    {
        $this->actingAs(User::whereHas('employeeProfile')->firstOrFail());
        session(['otp_verified' => true]);

        return $this->get(route('leave.form6', $this->request)
            .($paper === null ? '' : '?paper='.urlencode($paper)));
    }

    protected function download(?string $paper): string
    {
        // stream() hands back a plain response under test, not a streamed one.
        return $this->get6($paper)->assertOk()->getContent();
    }

    /** @return array{0:int,1:int} */
    private function mediaBox(string $pdf): array
    {
        preg_match('#/MediaBox \[([^\]]+)\]#', $pdf, $m);
        $this->assertNotEmpty($m, 'the response is not a PDF with a media box');
        $parts = preg_split('/\s+/', trim($m[1]));

        return [(int) round((float) $parts[2]), (int) round((float) $parts[3])];
    }

    /** Each size asked for is the size that comes back. */
    public function test_every_offered_paper_is_honoured(): void
    {
        foreach (self::EXPECTED as $paper => [$w, $h]) {
            $this->assertSame([$w, $h], $this->mediaBox($this->download($paper)),
                "$paper did not come out at its own dimensions");
        }
    }

    /**
     * All four hold the whole sheet on ONE page -- for the worst applicant.
     *
     * This is the assertion that makes the choice safe to offer, and it is
     * deliberately not run against the tidy fixture. With a short name the
     * sheet needs 777pt and every size looks fine; with a real one -- a long
     * hyphenated surname, a full office title -- it needs 791pt, and Letter is
     * 792. A version of this test that used the tidy fixture passed while
     * Letter had one point of margin, which is no margin at all.
     *
     * Letter is the shortest at 792pt and so fails first. If a future change
     * to the form pushes it over, this catches it long before anyone prints a
     * chopped application.
     */
    public function test_the_sheet_never_splits(): void
    {
        $this->stressLongestContent();

        foreach (array_keys(self::EXPECTED) as $paper) {
            $pdf = $this->download($paper);
            preg_match_all('#/Type\s*/Page[^s]#', $pdf, $pages);

            $this->assertCount(1, $pages[0], "CSC Form 6 runs to more than one page on $paper");
        }
    }

    /**
     * The longest name, office and position this LGU plausibly has.
     *
     * office_snapshot and position_snapshot rather than the related records:
     * the form prints the snapshot in preference to the live department, so a
     * reprint shows the office as it stood on the day of filing. Setting the
     * Department and Position rows instead changes nothing on the sheet -- an
     * earlier version of this helper did exactly that and "stressed" a form
     * that still rendered the short fixture values.
     */
    protected function stressLongestContent(): void
    {
        $this->request->user->employeeProfile->update([
            'first_name' => 'Ma. Cristina Bernadette',
            'last_name' => 'Villanueva-Dela Cruz',
        ]);

        $this->request->update([
            'office_snapshot' => 'Office of the Municipal Social Welfare and Development Officer',
            'position_snapshot' => 'Administrative Officer V (Human Resource Management Officer III)',
        ]);
    }

    /**
     * Long bond when nothing is asked for, and when something absurd is.
     *
     * dompdf's setPaper() takes any string it knows plus an arbitrary
     * [x1,y1,x2,y2] array, so passing the query string through would hand the
     * caller the page geometry. It is allowlisted; anything else falls back
     * rather than failing, because a wrong paper size is a nuisance and a 500
     * on a download is worse.
     */
    public function test_an_unknown_paper_falls_back_to_long_bond(): void
    {
        $legal = self::EXPECTED[self::DEFAULT_PAPER];

        $this->assertSame($legal, $this->mediaBox($this->download(null)),
            'no paper parameter should mean long bond');

        foreach (['', 'tabloid', 'A0', '[0,0,9999,9999]', '8.5x13'] as $junk) {
            $this->assertSame($legal, $this->mediaBox($this->download($junk)),
                "'$junk' was not rejected in favour of ".self::DEFAULT_PAPER);
        }
    }

    /**
     * An attack-shaped paper never reaches the allowlist at all.
     *
     * This started as a sixth entry in the fallback loop above, on the
     * assumption that `../../etc/passwd` would be treated as one more unknown
     * size and quietly served on the default paper. It came back 400: the
     * intrusion detection middleware matches the traversal signature and
     * refuses the request before the controller runs.
     *
     * That is the better outcome and it is worth pinning, because the two
     * behaviours are easy to confuse. A nonsense size is a typo and gets a
     * form; a traversal string is an attempt and gets a refusal and a logged
     * alert. If the IDS ever stops covering query strings, this fails rather
     * than degrading silently into the fallback above.
     */
    public function test_a_traversal_attempt_is_refused_rather_than_defaulted(): void
    {
        // Only the payload actually covered by the traversal signature. Two
        // others were tried here -- '..%2f..%2fetc%2fshadow' and 'c:\windows'
        // -- and both came back 200, because neither matches the rule as
        // written. Asserting them would have claimed IDS coverage this system
        // does not have; whether the signature should be widened is a question
        // for the IDS's own tests, not for a test about paper sizes.
        $this->get6('../../etc/passwd')->assertStatus(400);
    }

    /**
     * The type is bigger on the paper the LGU actually uses.
     *
     * The one-page test above only catches type that has grown too FAR. It
     * cannot catch type that has quietly shrunk -- a later change collapsing
     * the per-paper scale back to one shared bump would keep every size on one
     * page and silently undo the enlargement this form was asked for. Long
     * bond has 233pt of headroom and carries the full bump; Letter, at 792pt,
     * is the only size that cannot, and takes the largest that fits.
     */
    public function test_long_bond_is_set_larger_than_letter(): void
    {
        $bodySize = function (string $paper): float {
            $html = $this->download($paper);
            // The PDF's own text is compressed, so the scale is read from the
            // rendered view rather than from the file it produced.
            $css = view('leave.form6', $this->viewData($paper))->render();
            $this->assertNotEmpty($html);
            preg_match('/body \{[^}]*font-size:\s*([\d.]+)pt/', $css, $m);
            $this->assertNotEmpty($m, "no body font-size found for $paper");

            return (float) $m[1];
        };

        $legal = $bodySize('legal');
        $letter = $bodySize('letter');

        $this->assertSame(7.9, $legal, 'long bond should carry the full +1.5 bump on a 6.4pt base');
        $this->assertSame(7.2, $letter, 'letter should carry the largest bump that still fits it');
        $this->assertGreaterThan($letter, $legal,
            'the per-paper type scale has collapsed to a single bump');
    }

    /** @return array<string, mixed> */
    private function viewData(string $paper): array
    {
        return [
            'r' => $this->request->fresh([
                'leaveType', 'user.employeeProfile.department',
                'user.employeeProfile.position', 'approvals.approver',
            ]),
            'vl' => 10.5,
            'sl' => 8.0,
            'types' => LeaveType::orderBy('id')->get(),
            'paper' => $paper,
        ];
    }

    /** The picker offers exactly the sizes the controller will accept. */
    public function test_the_picker_and_the_allowlist_agree(): void
    {
        $this->actingAs(User::whereHas('employeeProfile')->firstOrFail());
        session(['otp_verified' => true]);

        $html = $this->get(route('leave.show', $this->request))->assertOk()->getContent();

        foreach (array_keys(self::EXPECTED) as $paper) {
            $this->assertStringContainsString('paper='.$paper, $html,
                "the picker does not offer $paper");
        }
    }
}
