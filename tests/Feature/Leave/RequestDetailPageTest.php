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
 * What the "View" page says about an application.
 *
 * Two things on it were leaking the database onto the screen. The approval
 * timeline was hand-rolled here with a role map keyed 'department_head', 'hr'
 * and 'mayor' -- against slugs that are actually 'department' and
 * 'authorized', so nothing ever matched and every row printed the raw slug: a
 * step called "authorized", in lower case, on a page an officer reads. And the
 * `details` bag was printed key by key, title-cased, which turned
 * `purpose_other` into "Purpose Other" and printed the stored code `bar` as a
 * value.
 *
 * The bag also carries a `purpose` key -- the study-leave question -- while
 * the application has a `purpose` column of its own, so the page showed two
 * rows both labelled "Purpose" holding different answers.
 */
class RequestDetailPageTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;

    private User $head;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();

        $office = Department::create(['name' => "Municipal Treasurer's Office", 'code' => 'MTO']);
        $position = Position::factory()->create(['title' => 'Administrative Assistant II']);

        $this->employee = $this->makeUser('employee');
        $this->employee->update(['name' => 'Josh Kirby B. Bote']);
        EmployeeProfile::factory()->create([
            'user_id' => $this->employee->id, 'employee_no' => 'EMP-0014',
            'department_id' => $office->id, 'position_id' => $position->id,
        ]);

        $this->head = $this->makeUser('department-head');
        $this->head->update(['name' => 'Engr. Maria S. Bumanglag']);
        EmployeeProfile::factory()->create([
            'user_id' => $this->head->id, 'employee_no' => 'EMP-0002',
            'department_id' => $office->id, 'position_id' => $position->id,
        ]);
        $office->update(['head_user_id' => $this->head->id]);

        foreach (['VL', 'STL'] as $code) {
            LeaveBalance::create([
                'user_id' => $this->employee->id,
                'leave_type_id' => LeaveType::where('code', $code)->firstOrFail()->id,
                'earned' => 12, 'used' => 0, 'balance' => 12,
            ]);
        }
    }

    /** A study-leave application, which is the one that fills the details bag. */
    private function file(): LeaveRequest
    {
        return app(LeaveApplicationService::class)->submit(
            $this->employee->fresh(),
            LeaveType::where('code', 'STL')->firstOrFail(),
            [
                'date_filed' => '2026-09-04', 'start_date' => '2026-09-24', 'end_date' => '2026-10-27',
                'purpose' => 'BAR review',
                'details' => ['purpose' => 'bar', 'purpose_other' => 'Bar examination review classes'],
                'applicant_signature' => 'Josh Kirby B. Bote',
            ]
        );
    }

    private function asHr(): self
    {
        $this->actingAs($this->makeUser('hr'));
        session(['otp_verified' => true]);

        return $this;
    }

    private function page(LeaveRequest $r): string
    {
        return $this->get(route('leave.show', $r))->assertOk()->getContent();
    }

    /** No role slug reaches the screen. */
    public function test_the_timeline_never_prints_a_raw_role_slug(): void
    {
        $html = $this->asHr()->page($this->file());

        $this->assertStringNotContainsString('>authorized<', $html,
            'the deciding step is labelled with its database slug');
        $this->assertStringNotContainsString('>department<', $html,
            'the notified step is labelled with its database slug');

        // What it should say instead, from the shared partial.
        $this->assertStringContainsString('Department Head Notified', $html);
        $this->assertStringContainsString('Engr. Maria S. Bumanglag', $html);
    }

    /** Detail keys are named, and unnamed ones are not printed at all. */
    public function test_detail_keys_are_labelled_not_title_cased(): void
    {
        $html = $this->asHr()->page($this->file());

        $this->assertStringNotContainsString('Purpose Other', $html,
            'a column name is being title-cased into a label');
        $this->assertStringContainsString('Other study purpose', $html);
        $this->assertStringContainsString('Study leave purpose', $html);
    }

    /** Stored codes are translated into what the form actually asked. */
    public function test_stored_codes_are_shown_as_words(): void
    {
        $html = $this->asHr()->page($this->file());

        $this->assertStringContainsString('BAR examination review', $html,
            'the study-leave answer is still the stored code');
        $this->assertStringNotContainsString('>bar<', $html);
    }

    /**
     * One row per label.
     *
     * The bag's `purpose` and the application's `purpose` column are different
     * questions with the same name, and both were printed as "Purpose".
     */
    public function test_purpose_is_not_listed_twice(): void
    {
        $html = $this->asHr()->page($this->file());

        $this->assertSame(1, substr_count($html, '<dt>Purpose</dt>'),
            'two rows are both labelled "Purpose"');
    }

    /**
     * HR is not addressed as the applicant.
     *
     * The shared timeline is written for the employee's own pages -- "you will
     * be away", "you cancelled this". Reused here unchanged it told an officer
     * that they had filed the application they are deciding.
     */
    public function test_an_officer_is_not_told_it_is_their_own_leave(): void
    {
        $html = $this->asHr()->page($this->file());
        $this->assertStringContainsString('informed of this absence', $html);

        $this->actingAs($this->employee);
        session(['otp_verified' => true]);
        $own = $this->page(LeaveRequest::where('user_id', $this->employee->id)->firstOrFail());
        $this->assertStringContainsString('informed of your absence', $own,
            'the applicant is no longer addressed directly on their own request');
    }

    /** Every control on the upload row carries a label, the file input included. */
    public function test_the_upload_row_labels_its_file_input(): void
    {
        $r = $this->file();
        $this->actingAs($this->employee);
        session(['otp_verified' => true]);

        $html = $this->page($r);

        $this->assertStringContainsString('for="doc-file-'.$r->id.'"', $html,
            'the file input has no label, and it is the control that decides what is uploaded');
        $this->assertStringContainsString('for="doc-type-'.$r->id.'"', $html);
    }

    /** The employee page shows credits as tiles, under their own class name. */
    public function test_the_employee_page_shows_credits_and_role_chips(): void
    {
        $html = $this->asHr()
            ->get(route('employees.show', $this->employee))->assertOk()->getContent();

        $this->assertStringContainsString('cr-tile', $html);
        $this->assertStringContainsString('Leave credits', $html);

        // `bal-k` on the dashboard is a background colour for a balance bar.
        // Borrowing it for a label put the text on a violet block.
        $this->assertStringNotContainsString('cr-tile"><span class="bal-', $html);
    }
}
