<?php

namespace Tests\Feature\Admin;

use App\Models\Archive;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\EmployeeProfile;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * An account is archived, never destroyed.
 *
 * forceDelete() cascaded through every leave application the person had ever
 * filed — approved ones included, each backed by a signed CSC Form 6 — along
 * with their approvals, uploaded documents, balances and credit history. It
 * then nulled their name out of the audit, activity and intrusion logs, and
 * deleting an approver stripped their name off other people's approved
 * applications as well.
 *
 * A system whose case rests on auditability cannot offer that operation, and
 * it never needed to: archiving already covers every reason an account stops
 * being used — resigned, dismissed, died.
 *
 * It also settles the employee number. Nothing is ever destroyed, so no number
 * is ever freed, so none can be reissued to somebody else.
 */
class NoHardDeleteTest extends TestCase
{
    use RefreshDatabase;

    private User $leaver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();

        $department = Department::factory()->create();
        $position = Position::factory()->create();

        $this->leaver = $this->makeUser('employee');
        EmployeeProfile::factory()->create([
            'user_id' => $this->leaver->id, 'employee_no' => 'EMP-0001',
            'department_id' => $department->id, 'position_id' => $position->id,
        ]);
        LeaveRequest::factory()->create([
            'user_id' => $this->leaver->id,
            'leave_type_id' => LeaveType::where('code', 'VL')->firstOrFail()->id,
            'status' => 'approved',
        ]);

        $this->actingAs($this->makeUser('system-admin'));
        session(['otp_verified' => true]);
    }

    /** There is no route to it, so a replayed form cannot reach one either. */
    public function test_the_system_offers_no_way_to_delete_an_account(): void
    {
        $this->assertNull(Route::getRoutes()->getByName('users.destroy'),
            'a permanent delete route is back');

        $this->assertFalse(method_exists(\App\Http\Controllers\Admin\UserController::class, 'destroy'),
            'the controller can still destroy an account');

        // 405 rather than 404: the URI still exists for PUT, so the method
        // itself is refused. What matters is that it does not succeed and the
        // account is still there afterwards.
        $this->delete('/users/'.$this->leaver->id)->assertMethodNotAllowed();
        $this->assertDatabaseHas('users', ['id' => $this->leaver->id]);
        $this->assertDatabaseHas('leave_requests', ['user_id' => $this->leaver->id]);
    }

    /** Nothing in the interface offers it either. */
    public function test_nothing_on_the_page_offers_a_permanent_delete(): void
    {
        $html = $this->get('/users')->assertOk()->getContent();

        $this->assertStringNotContainsString('Delete permanently', $html);
        $this->assertStringNotContainsString("@method('DELETE')", $html);
        $this->assertStringContainsString('Archive', $html);
    }

    // ------------------------------------------------- what archiving preserves

    public function test_archiving_keeps_everything_the_record_rests_on(): void
    {
        $this->post('/users/'.$this->leaver->id.'/archive')->assertRedirect();

        $this->assertSoftDeleted('users', ['id' => $this->leaver->id]);

        // The leave record survives: an approved application is an official
        // document, not a convenience.
        $this->assertDatabaseHas('leave_requests', ['user_id' => $this->leaver->id]);
        $this->assertDatabaseHas('employee_profiles', ['employee_no' => 'EMP-0001']);
        $this->assertNotNull(Archive::where('archivable_id', $this->leaver->id)->first(),
            'nothing recorded that the account was archived, or by whom');
        $this->assertNotNull(AuditLog::where('action', 'user_archived')->first());
    }

    /** And it can be undone, which a deletion never could. */
    public function test_an_archived_account_can_come_back(): void
    {
        $this->post('/users/'.$this->leaver->id.'/archive')->assertRedirect();
        $this->post('/users/'.$this->leaver->id.'/restore')->assertRedirect();

        $this->assertNotSoftDeleted('users', ['id' => $this->leaver->id]);
    }

    /**
     * The number stays counted while the person is archived, so the next
     * account gets the one after it rather than theirs.
     */
    public function test_an_archived_employees_number_is_never_handed_out_again(): void
    {
        $this->post('/users/'.$this->leaver->id.'/archive')->assertRedirect();

        $this->assertSame('EMP-0002', EmployeeProfile::nextEmployeeNo(),
            'a resigned employee\'s number came back into circulation');
    }

    /** Blocking and deactivating are still there — they are different things. */
    public function test_the_lesser_measures_are_untouched(): void
    {
        $this->assertNotNull(Route::getRoutes()->getByName('users.block'));
        $this->assertNotNull(Route::getRoutes()->getByName('users.toggle-active'));
        $this->assertNotNull(Route::getRoutes()->getByName('users.archive'));
        $this->assertNotNull(Route::getRoutes()->getByName('users.restore'));
    }
}
