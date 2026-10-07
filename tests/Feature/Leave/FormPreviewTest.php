<?php

namespace Tests\Feature\Leave;

use App\Models\Department;
use App\Models\EmployeeProfile;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\User;
use App\Services\Leave\LeaveApplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The on-screen preview and the PDF are one document.
 *
 * They are two files -- preview-form.blade.php draws in HTML and CSS, form6
 * .blade.php draws in the table subset dompdf understands -- and they drifted:
 * the PDF was refined with the municipal seal, the One Alicia mark, a
 * single-row masthead and a Times face, and the preview kept two Bootstrap
 * glyphs in circles, a two-row header, Arial, and the form chopped into three
 * separately bordered "Part n of 3" sheets.
 *
 * That is not cosmetic. The preview's whole claim is "this is what will be
 * filed", and an employee who checks their application there and then prints
 * something that looks different has been told a lie by the interface.
 *
 * These hold the parts of the claim that a future edit to one file could break
 * without touching the other.
 */
class FormPreviewTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;

    private function file(): LeaveRequest
    {
        $this->seedCore();

        $office = Department::create(['name' => 'Municipal Engineering Office', 'code' => 'MEO']);
        $vl = LeaveType::where('code', 'VL')->firstOrFail();

        $this->employee = $this->makeUser('employee');
        $this->employee->update(['name' => 'Juan P. Dela Cruz']);
        EmployeeProfile::factory()->create([
            'user_id' => $this->employee->id, 'employee_no' => 'EMP-0001',
            'first_name' => 'Juan', 'last_name' => 'Dela Cruz',
            'department_id' => $office->id, 'position_id' => Position::factory()->create()->id,
        ]);

        LeaveBalance::create([
            'user_id' => $this->employee->id, 'leave_type_id' => $vl->id,
            'earned' => 30, 'used' => 0, 'balance' => 30,
        ]);

        return app(LeaveApplicationService::class)->submit($this->employee->fresh(), $vl, [
            'date_filed' => '2026-07-01', 'start_date' => '2026-07-13', 'end_date' => '2026-07-15',
            'purpose' => 'Family matters',
            'details' => ['location' => 'within_ph', 'location_specify' => 'Alicia, Isabela'],
            'applicant_signature' => 'Juan P. Dela Cruz',
        ]);
    }

    private function preview(LeaveRequest $r): string
    {
        $this->actingAs($this->employee);
        session(['otp_verified' => true]);

        return $this->get(route('leave.preview-form', $r))->assertOk()->getContent();
    }

    /** The real seal and the real mark, not two icons standing in for them. */
    public function test_the_masthead_carries_the_seal_and_the_one_alicia_mark(): void
    {
        $html = $this->preview($this->file());

        $this->assertStringContainsString('img/alicia-seal.png', $html,
            'the preview masthead does not carry the municipal seal');
        $this->assertStringContainsString('img/one-alicia.png', $html,
            'the preview masthead does not carry the One Alicia mark');

        $this->assertStringNotContainsString('bi-buildings', $html,
            'the placeholder glyph is still standing in for the seal');
    }

    /**
     * One sheet.
     *
     * The document the LGU files is a single sheet of paper. Three bordered
     * boxes with gaps between them is a different shape from the thing being
     * previewed, whatever the boxes contain.
     */
    public function test_the_form_is_one_continuous_sheet(): void
    {
        $html = $this->preview($this->file());

        $this->assertSame(1, substr_count($html, 'class="csc-sheet'),
            'the form is drawn as more than one sheet');
        $this->assertStringNotContainsString('Part 1 of 3', $html);
    }

    /**
     * The download IS the printable copy; there is no separate Print button.
     *
     * There used to be one, running window.print() over this markup -- a
     * second renderer with its own fonts, its own margins and no page budget,
     * measured at two pages on long bond where PaperSizeTest holds the PDF to
     * one on all four sizes. Pointing it at the PDF fixed that and made it
     * redundant in the same move, since that is the route the button beside it
     * already opens.
     */
    public function test_the_only_printable_copy_is_the_pdf(): void
    {
        $r = $this->file();
        $html = $this->preview($r);

        $this->assertStringNotContainsString('window.print()', $html,
            'the page is still printed through the browser, which pages it wrong');
        $this->assertStringContainsString(route('leave.form6', $r), $html,
            'nothing on the preview reaches the PDF the form is actually filed as');
        $this->assertSame(0, substr_count($html, '>Print<'),
            'Print is back beside Download Form, two controls opening one route');
    }

    /**
     * Set in the face the PDF embeds, from the same files.
     *
     * The preview was Arial against a Times document -- the loudest way two
     * renderings of one page can disagree before a word is compared.
     */
    public function test_the_preview_is_set_in_the_documents_own_face(): void
    {
        $html = $this->preview($this->file());
        $this->assertStringContainsString('csc-doc', $html,
            'the sheet is not marked as the filed document');

        $css = file_get_contents(public_path('css/app.css'));
        $this->assertMatchesRegularExpression('/\.csc-doc\{[^}]*LibSerif/', $css,
            'the preview is not set in the face the PDF embeds');
        $this->assertStringContainsString('LiberationSerif-Regular.ttf', $css,
            'the stylesheet does not load the vendored face, so the preview '
            .'falls back to whatever Times the reader happens to have');
    }
}
