<?php

namespace Tests\Feature\Navigation;

use App\Models\Department;
use App\Models\EmployeeProfile;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every screen reached *from* another screen offers a way back to it.
 *
 * Forty-one of the system's forty-four screens had none, so the browser button
 * was the only route out — and on the pages that open in a new tab, notably a
 * report opened with the View button, even that does nothing. The back link is
 * a real <a href> for exactly that reason.
 */
class BackLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
    }

    private function signIn(string $role): User
    {
        $user = $this->makeUser($role);
        $this->actingAs($user);
        session(['otp_verified' => true]);

        return $user;
    }

    private function assertGoesBackTo(string $url, string $parentRoute, string $label): void
    {
        $html = $this->get($url)->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<a href="'.preg_quote(route($parentRoute), '/').'"[^>]*class="back-link"/',
            $html,
            "{$url} has no back link to {$parentRoute}"
        );
        $this->assertStringContainsString($label, $html);
    }

    public function test_a_report_result_goes_back_to_the_reports_page(): void
    {
        $this->signIn('system-admin');
        $this->assertGoesBackTo('/reports/audit', 'reports.index', 'Reports');
    }

    public function test_the_administration_forms_go_back_to_their_lists(): void
    {
        $admin = $this->signIn('system-admin');

        $this->assertGoesBackTo('/users/create', 'users.index', 'Users');
        $this->assertGoesBackTo('/users/'.$admin->id.'/edit', 'users.index', 'Users');
        $this->assertGoesBackTo('/users/'.$admin->id.'/history', 'users.index', 'Users');
        $this->assertGoesBackTo('/roles/create', 'roles.index', 'Roles');
    }

    public function test_an_employee_reaches_their_own_list_from_a_request(): void
    {
        $employee = $this->signIn('employee');
        $request = $this->fileFor($employee);

        $this->assertGoesBackTo('/leave/'.$request->id, 'leave.index', 'My Leave Requests');
        $this->assertGoesBackTo('/leave-instructions', 'leave.create', 'Apply for Leave');
    }

    /**
     * The one page with two parents. An approver arrives from All Leave
     * Requests, so sending them to My Leave Requests would drop them on a list
     * that does not contain the record they were just reading.
     */
    public function test_an_approver_reaches_the_all_requests_list_instead(): void
    {
        $employee = $this->makeUser('employee');
        $request = $this->fileFor($employee);

        $this->signIn('hr');
        $this->assertGoesBackTo('/leave/'.$request->id, 'leave.all', 'All Leave Requests');
    }

    public function test_an_employee_profile_goes_back_to_the_employee_list(): void
    {
        $this->signIn('hr');
        $employee = $this->makeUser('employee');
        EmployeeProfile::factory()->create([
            'user_id' => $employee->id,
            'department_id' => Department::factory()->create()->id,
        ]);

        $this->assertGoesBackTo('/employees/'.$employee->id, 'employees.index', 'Employees');
    }

    /**
     * A back link on a screen the sidebar already reaches has nowhere honest to
     * point, and putting one everywhere is how a header stops meaning anything.
     */
    public function test_the_sidebar_pages_carry_no_back_link(): void
    {
        $this->signIn('system-admin');

        foreach (['/dashboard', '/users', '/roles', '/security', '/reports'] as $url) {
            $this->assertStringNotContainsString('class="back-link"',
                $this->get($url)->assertOk()->getContent(),
                "{$url} is reachable from the sidebar and should not offer a way 'back'");
        }
    }

    /**
     * A browser-history control would be inert on a page opened in a new tab,
     * which is how the Reports View button opens its result. The link has to be
     * a real destination.
     */
    public function test_the_back_link_is_a_real_link_not_a_history_control(): void
    {
        $this->signIn('system-admin');
        $html = $this->get('/reports/audit')->assertOk()->getContent();

        $this->assertStringNotContainsString('history.back', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    private function fileFor(User $user): LeaveRequest
    {
        EmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'department_id' => Department::factory()->create()->id,
        ]);

        return LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'leave_type_id' => LeaveType::where('code', 'VL')->firstOrFail()->id,
            'status' => 'approved',
        ]);
    }
}
