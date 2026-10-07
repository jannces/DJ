<?php

namespace Tests\Feature\Leave;

use App\Models\Approval;
use App\Models\Department;
use App\Models\EmployeeProfile;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\User;
use App\Services\Leave\ApprovalWorkflowService;
use App\Services\Leave\LeaveApplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Box 7.B: the head of the applicant's office recommends.
 *
 * There was nothing here before. The head was NOTIFIED when an application was
 * filed and never asked anything, so 7.B printed their name over two empty
 * boxes and a blank line -- and a head who had uploaded a signature never saw
 * it on a form, because nothing in the system had ever asked them to sign one.
 *
 * A RECOMMENDATION, not a decision. HR decides either way, and these hold that
 * distinction as much as they hold the mechanics: nothing here may touch the
 * application's status, and a head who does not recommend must not stop it.
 */
class RecommendationTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;

    private User $head;

    private LeaveRequest $request;

    private Department $office;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();

        $this->office = Department::create(['name' => 'Municipal Engineering Office', 'code' => 'MEO']);
        $position = Position::factory()->create();
        $vl = LeaveType::where('code', 'VL')->firstOrFail();

        $this->employee = $this->makeUser('employee');
        $this->employee->update(['name' => 'Juan Dela Cruz']);
        EmployeeProfile::factory()->create([
            'user_id' => $this->employee->id, 'employee_no' => 'EMP-0001',
            'department_id' => $this->office->id, 'position_id' => $position->id,
        ]);

        $this->head = $this->makeUser('department-head');
        $this->head->update(['name' => 'Engr. Maria S. Bumanglag']);
        EmployeeProfile::factory()->create([
            'user_id' => $this->head->id, 'employee_no' => 'EMP-0002',
            'department_id' => $this->office->id, 'position_id' => $position->id,
        ]);
        $this->office->update(['head_user_id' => $this->head->id]);

        LeaveBalance::create([
            'user_id' => $this->employee->id, 'leave_type_id' => $vl->id,
            'earned' => 30, 'used' => 0, 'balance' => 30,
        ]);

        $this->request = app(LeaveApplicationService::class)->submit($this->employee->fresh(), $vl, [
            'date_filed' => '2026-07-01', 'start_date' => '2026-07-13', 'end_date' => '2026-07-15',
            'purpose' => 'Family matters',
            'details' => ['location' => 'within_ph', 'location_specify' => 'Alicia, Isabela'],
            'applicant_signature' => 'Juan Dela Cruz',
        ]);
    }

    private function signIn(User $user): self
    {
        $this->actingAs($user);
        session(['otp_verified' => true]);

        return $this;
    }

    /** Give the head a signature on file, as one who had uploaded one would. */
    private function headSigns(): void
    {
        $canvas = imagecreatetruecolor(1200, 300);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagesetthickness($canvas, 10);
        imageline($canvas, 60, 220, 1140, 90, imagecolorallocate($canvas, 10, 10, 40));
        $file = tempnam(sys_get_temp_dir(), 'sig').'.png';
        imagepng($canvas, $file);
        imagedestroy($canvas);

        $this->signIn($this->head)->post(route('signature.store'), [
            'signature' => new UploadedFile($file, 'sig.png', 'image/png', null, true),
        ])->assertRedirect();
    }

    private function headRow(): Approval
    {
        return $this->request->approvals()
            ->where('role_slug', ApprovalWorkflowService::STEP_DEPARTMENT)
            ->firstOrFail();
    }

    // ------------------------------------------------------------ the action

    public function test_the_head_can_recommend_their_own_offices_application(): void
    {
        $this->signIn($this->head)
            ->post(route('leave.recommend', $this->request), ['recommendation' => 'recommended'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $row = $this->headRow();
        $this->assertSame(Approval::ACTION_RECOMMENDED, $row->action);
        $this->assertSame($this->head->id, $row->approver_id);
        $this->assertSame('Engr. Maria S. Bumanglag', $row->signature);
        $this->assertNotNull($row->acted_at);
    }

    /**
     * It is a recommendation. It does not decide anything.
     *
     * The application was pending before and is pending after, whichever way
     * the head answered -- HR decides it. A head who could stop an application
     * by not recommending it would be a second approver, which is not what box
     * 7.B is.
     */
    public function test_a_recommendation_does_not_move_the_application(): void
    {
        foreach (['recommended', 'not_recommended'] as $answer) {
            $this->request->approvals()
                ->where('role_slug', ApprovalWorkflowService::STEP_DEPARTMENT)
                ->update(['action' => Approval::ACTION_NOTIFIED]);

            $before = $this->request->fresh();

            $this->signIn($this->head)->post(route('leave.recommend', $this->request), [
                'recommendation' => $answer,
                'reason' => 'The office is short-staffed on those dates.',
            ])->assertRedirect();

            $after = $this->request->fresh();

            $this->assertSame($before->status, $after->status, "{$answer} changed the status");
            $this->assertSame($before->current_step, $after->current_step,
                "{$answer} moved the application to another step");
        }
    }

    /** Refusing to recommend needs a reason; recommending does not. */
    public function test_a_refusal_must_say_why(): void
    {
        $this->signIn($this->head)
            ->from(route('leave.show', $this->request))
            ->post(route('leave.recommend', $this->request), ['recommendation' => 'not_recommended'])
            ->assertSessionHasErrors('reason');

        $this->assertSame(Approval::ACTION_NOTIFIED, $this->headRow()->action);
    }

    /** Once only: a second answer would overwrite a signature already printed. */
    public function test_a_head_cannot_recommend_twice(): void
    {
        $this->signIn($this->head)
            ->post(route('leave.recommend', $this->request), ['recommendation' => 'recommended'])
            ->assertRedirect();

        $this->signIn($this->head)
            ->from(route('leave.show', $this->request))
            ->post(route('leave.recommend', $this->request), [
                'recommendation' => 'not_recommended', 'reason' => 'Changed my mind',
            ])
            ->assertSessionHasErrors('recommendation');

        $this->assertSame(Approval::ACTION_RECOMMENDED, $this->headRow()->action);
    }

    // ------------------------------------------------------------------ scope

    /**
     * The permission says "may review a department", not WHICH department.
     *
     * Without the scope check any head could recommend on anybody in the LGU
     * by posting the right reference number.
     */
    public function test_a_head_of_another_office_is_refused(): void
    {
        $other = Department::create(['name' => 'Municipal Health Office', 'code' => 'MHO']);
        $stranger = $this->makeUser('department-head');
        EmployeeProfile::factory()->create([
            'user_id' => $stranger->id, 'employee_no' => 'EMP-0003',
            'department_id' => $other->id, 'position_id' => Position::factory()->create()->id,
        ]);
        $other->update(['head_user_id' => $stranger->id]);

        $this->signIn($stranger)
            ->post(route('leave.recommend', $this->request), ['recommendation' => 'recommended'])
            ->assertForbidden();

        $this->assertSame(Approval::ACTION_NOTIFIED, $this->headRow()->action);
    }

    public function test_an_ordinary_employee_cannot_recommend(): void
    {
        $this->signIn($this->employee)
            ->post(route('leave.recommend', $this->request), ['recommendation' => 'recommended'])
            ->assertForbidden();
    }

    /** Nobody recommends on their own leave, head or not. */
    public function test_a_head_cannot_recommend_on_their_own_application(): void
    {
        LeaveBalance::create([
            'user_id' => $this->head->id,
            'leave_type_id' => LeaveType::where('code', 'VL')->firstOrFail()->id,
            'earned' => 30, 'used' => 0, 'balance' => 30,
        ]);

        $own = app(LeaveApplicationService::class)->submit($this->head->fresh(),
            LeaveType::where('code', 'VL')->firstOrFail(), [
                'date_filed' => '2026-07-01', 'start_date' => '2026-08-03', 'end_date' => '2026-08-04',
                'purpose' => 'Personal',
                'details' => ['location' => 'within_ph', 'location_specify' => 'Alicia'],
                'applicant_signature' => 'Engr. Maria S. Bumanglag',
            ]);

        $this->signIn($this->head)
            ->post(route('leave.recommend', $own), ['recommendation' => 'recommended'])
            ->assertForbidden();
    }

    // ------------------------------------------------------- the printed form

    /**
     * The signature reaches box 7.B -- which is the whole point of this.
     *
     * A head had uploaded one and it never appeared, because the form only
     * ever printed their name.
     */
    public function test_the_heads_signature_is_printed_once_they_recommend(): void
    {
        $this->headSigns();

        $this->signIn($this->head)
            ->post(route('leave.recommend', $this->request), ['recommendation' => 'recommended'])
            ->assertRedirect();

        $row = $this->headRow();
        $this->assertNotNull($row->signature_path, 'no signature was snapshotted onto the approval');
        $this->assertTrue(Storage::disk('local')->exists($row->signature_path));

        $html = view('leave.form6', [
            'r' => $this->request->fresh()->load('approvals.approver', 'leaveType', 'user.employeeProfile'),
            'vl' => 30.0, 'sl' => 0.0, 'paper' => 'legal',
            'types' => LeaveType::orderBy('name')->get(),
        ])->render();

        $this->assertStringContainsString(
            Storage::disk('local')->path($row->signature_path), $html,
            'the head signed, and box 7.B still prints no signature');
    }

    /**
     * And it is NOT printed while they have not recommended.
     *
     * A signature over two empty boxes would be the system recording a
     * recommendation nobody made.
     */
    public function test_nothing_is_printed_in_7b_before_the_head_acts(): void
    {
        $this->headSigns();

        $html = view('leave.form6', [
            'r' => $this->request->fresh()->load('approvals.approver', 'leaveType', 'user.employeeProfile'),
            'vl' => 30.0, 'sl' => 0.0, 'paper' => 'legal',
            'types' => LeaveType::orderBy('name')->get(),
        ])->render();

        $this->assertStringNotContainsString('signatures/filed/approval-', $html,
            'a signature is printed in 7.B although the head has recommended nothing');

        // The name still prints: the form has to say which head was informed.
        $this->assertStringContainsString('Engr. Maria S. Bumanglag', $html);
    }

    /** The snapshot is the head's own copy, not a pointer at their profile. */
    public function test_replacing_the_signature_does_not_change_a_filed_form(): void
    {
        $this->headSigns();
        $this->signIn($this->head)
            ->post(route('leave.recommend', $this->request), ['recommendation' => 'recommended']);

        $filed = $this->headRow()->signature_path;

        $this->headSigns(); // uploads a replacement, deleting the previous file

        $this->assertTrue(Storage::disk('local')->exists($filed),
            'replacing a signature destroyed the one already printed on a filed form');
        $this->assertSame($filed, $this->headRow()->signature_path);
    }

    /** The applicant is told, and the timeline says what happened. */
    public function test_the_timeline_reports_the_recommendation(): void
    {
        $this->signIn($this->head)->post(route('leave.recommend', $this->request), [
            'recommendation' => 'not_recommended',
            'reason' => 'The office is short-staffed on those dates.',
        ])->assertRedirect();

        $html = $this->signIn($this->employee)
            ->get(route('leave.show', $this->request))->assertOk()->getContent();

        $this->assertStringContainsString('Not Recommended by Department Head', $html);
        $this->assertStringContainsString('short-staffed', $html);
    }
}
