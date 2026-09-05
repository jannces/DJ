<?php

namespace Tests\Feature\Leave;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The blank CSC Form 6 -- the one printed to be filled in by hand.
 *
 * The Apply page's Print button used to run window.print() over the web entry
 * form and produce three pages of rounded cards, bordered input boxes and a
 * date-picker widget: the application software, printed. It renders the form
 * itself now, through the SAME template a filed application uses, because a
 * separate blank template would be a second thing to keep in step with the
 * first -- the drift that left the on-screen preview a redesign behind.
 *
 * Sharing that template is what these guard. Every field on it is written
 * against a request that is not there, so any one of them could put a null
 * dereference on a page nobody looks at until an office needs paper.
 */
class BlankFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
    }

    private function asHr(): self
    {
        $this->actingAs($this->makeUser('hr'));
        session(['otp_verified' => true]);

        return $this;
    }

    private function pdf(string $query = ''): string
    {
        return $this->asHr()
            ->get(route('leave.form6-blank').$query)
            ->assertOk()->getContent();
    }

    /** The template's own output, before dompdf turns it into a PDF. */
    private function blankHtml(): string
    {
        return view('leave.form6', [
            'r' => null, 'vl' => 0.0, 'sl' => 0.0, 'paper' => 'legal',
            'types' => \App\Models\LeaveType::orderBy('name')->get(),
        ])->render();
    }

    /** It renders at all: no field dereferences the application that is absent. */
    public function test_the_blank_form_renders(): void
    {
        $this->assertStringStartsWith('%PDF', $this->pdf());
    }

    /**
     * One page, on every size the picker offers.
     *
     * The same guarantee PaperSizeTest holds for a filed application. A blank
     * form that runs onto a second sheet is two sheets to hand an applicant
     * and two to file.
     */
    public function test_it_fits_one_page_on_every_paper_size(): void
    {
        foreach (['legal', 'folio', 'a4', 'letter'] as $paper) {
            $pdf = $this->pdf('?paper='.$paper);

            $this->assertSame(1, substr_count($pdf, '/Type /Page') - substr_count($pdf, '/Type /Pages'),
                "the blank form runs onto a second page on {$paper}");
        }
    }

    /**
     * Blank means blank.
     *
     * Not "—", not "N/A", not a ticked box. A dash printed where somebody has
     * to write is worse than the space it took, and a tick nobody made is the
     * form answering a question on the applicant's behalf.
     */
    public function test_nothing_on_it_is_filled_in(): void
    {
        $html = $this->blankHtml();

        $this->assertStringNotContainsString('>x<', $html,
            'a checkbox is ticked on a form nobody has filled in');
        $this->assertStringNotContainsString('—', $html,
            'an em dash is standing in for a value on a blank form');
        $this->assertStringNotContainsString('Reference', $html,
            'the blank form claims a reference number, so it reads as a filed application');
    }

    /** It still says what it is, and what to do with it. */
    public function test_it_names_itself_as_a_blank_copy(): void
    {
        $html = $this->blankHtml();

        $this->assertStringContainsString('blank copy', $html);
        $this->assertStringContainsString('APPLICATION FOR LEAVE', $html);
    }

    /** Signing in is still the gate; a blank form is not a way around it. */
    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->get(route('leave.form6-blank'))->assertRedirect(route('login'));
    }

    /**
     * HR's, not the applicant's.
     *
     * Blank paper is what an office hands across a counter. Offering it to an
     * employee on the page where they file without paper works against the
     * point of the system, so the route carries the permission only HR holds
     * rather than merely being left off the employee's menu.
     */
    public function test_an_employee_cannot_reach_the_blank_form(): void
    {
        $this->actingAs($this->makeUser('employee'));
        session(['otp_verified' => true]);

        $this->get(route('leave.form6-blank'))->assertForbidden();
    }

    /** And HR is offered it where they work, on the approval queue. */
    public function test_hr_is_offered_it_on_the_approval_queue(): void
    {
        $this->asHr()->get(route('review.index'))
            ->assertOk()
            ->assertSee('Blank form')
            ->assertSee(route('leave.form6-blank'));
    }

    /** The applicant's own pages do not offer it. */
    public function test_the_apply_page_no_longer_prints_itself(): void
    {
        $this->actingAs($this->makeUser('employee'));
        session(['otp_verified' => true]);

        $html = $this->get(route('leave.create'))->assertOk()->getContent();

        $this->assertStringNotContainsString('window.print()', $html,
            'the Apply page still prints the web form through the browser');
        $this->assertStringNotContainsString(route('leave.form6-blank'), $html);
    }
}
