<?php

namespace Tests\Feature\Navigation;

use App\Models\Department;
use App\Models\EmployeeProfile;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A person's name turns blue on hover, and blue means it goes somewhere.
 *
 * That is the whole rule. Blue on hover is the oldest signal on the web, so a
 * name painted with it and no destination is worse than a name left black --
 * and a name whose destination is not the one the rest of the row promises is
 * worse still.
 *
 * Two kinds of list, two answers:
 *
 *   · A list ABOUT PEOPLE -- employees, rankings, user accounts, balances --
 *     the name opens that person.
 *   · A list ABOUT LEAVE APPLICATIONS -- all requests, approvals, the waiting
 *     queue -- the name opens the application, because that is where the rest
 *     of the row goes. One row, one destination.
 *
 * The second was the correction. Pointing those names at the employee's record
 * would have put two destinations in one row with nothing on screen saying
 * which was which: an officer clicking a name to check a request would land on
 * an HR profile and have to come back.
 */
class NameLinkTest extends TestCase
{
    use RefreshDatabase;

    private Department $office;

    private User $employee;

    private LeaveRequest $application;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();

        $this->office = Department::create(['name' => 'Municipal Engineering Office', 'code' => 'MEO']);
        $position = Position::factory()->create();

        $this->employee = $this->makeUser('employee');
        $this->employee->update(['name' => 'Juan Dela Cruz']);
        EmployeeProfile::factory()->create([
            'user_id' => $this->employee->id, 'employee_no' => 'EMP-0001',
            'first_name' => 'Juan', 'last_name' => 'Dela Cruz',
            'department_id' => $this->office->id, 'position_id' => $position->id,
        ]);

        $this->application = LeaveRequest::factory()->create([
            'user_id' => $this->employee->id,
            'leave_type_id' => LeaveType::where('code', 'VL')->firstOrFail()->id,
            'status' => 'dept_review',
            'date_filed' => now()->subDays(9)->toDateString(),
            'start_date' => now()->addDays(3)->toDateString(),
            'end_date' => now()->addDays(4)->toDateString(),
        ]);
    }

    private function as(string $role): User
    {
        $user = $this->makeUser($role);
        $this->actingAs($user);
        session(['otp_verified' => true]);

        return $user;
    }

    // ------------------------------------------- lists about a leave application

    /**
     * @return array<string, array{0: string}>
     */
    public static function leaveLists(): array
    {
        return ['all requests' => ['/all-leave'], 'the dashboard queue' => ['/dashboard']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('leaveLists')]
    public function test_a_name_in_a_leave_list_opens_the_application(string $url): void
    {
        $this->as('hr');

        $html = $this->get($url)->assertOk()->getContent();
        $target = route('leave.show', $this->application);

        // The queue writes the name as "Dela Cruz, Juan" and the list as
        // "Juan Dela Cruz", so the assertion is on the link rather than on one
        // page's way of spelling it.
        $this->assertMatchesRegularExpression(
            '#<a href="'.preg_quote($target, '#').'"[^>]*class="[^"]*name-link[^"]*"[^>]*>\s*[^<]*Dela Cruz#s',
            $html,
            'the name does not open the application the row is about'
        );
        $this->assertStringNotContainsString(route('employees.show', $this->employee), $html,
            'the name points at the employee record while the row points at the request');
    }

    public function test_the_approval_queue_follows_the_same_rule(): void
    {
        $this->as('hr');

        $html = $this->get('/review')->assertOk()->getContent();

        $this->assertStringContainsString(
            '<a href="'.route('leave.show', $this->application).'" class="name-link fw-semibold">Juan Dela Cruz</a>',
            $html
        );
    }

    /** And the link actually opens, rather than landing on a 403. */
    public function test_the_link_in_a_leave_list_resolves(): void
    {
        $this->as('hr');

        $this->get(route('leave.show', $this->application))->assertOk();
    }

    /**
     * A department head reaches the queue for their own office, so the name in
     * it has to work for them too -- leave.show admits them only for their own
     * department, which is exactly what the queue is scoped to.
     */
    public function test_a_department_head_can_open_the_name_in_their_own_queue(): void
    {
        $head = $this->as('department-head');
        EmployeeProfile::factory()->create([
            'user_id' => $head->id, 'employee_no' => 'EMP-0002',
            'department_id' => $this->office->id,
            'position_id' => Position::factory()->create()->id,
        ]);
        $this->office->update(['head_user_id' => $head->id]);

        $html = $this->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString(route('leave.show', $this->application), $html);
        $this->get(route('leave.show', $this->application))->assertOk();
    }

    // ------------------------------------------------- lists about a person

    public function test_a_name_in_a_people_list_opens_the_person(): void
    {
        // The rankings count approved leave only, so there is nothing to rank
        // until this one is decided.
        $this->application->update(['status' => 'approved', 'decided_at' => now()]);
        $this->as('hr');

        foreach (['/employees', '/rankings', '/balances'] as $url) {
            $this->assertStringContainsString(
                route('employees.show', $this->employee),
                $this->get($url)->assertOk()->getContent(),
                $url.' does not open the employee record'
            );
        }
    }

    public function test_the_user_list_opens_the_account(): void
    {
        $this->as('system-admin');

        // The user list draws the shared person row now, so the link carries
        // the component's class alongside name-link.
        $this->assertStringContainsString(
            '<a href="'.route('users.edit', $this->employee).'" class="person-name name-link">Juan Dela Cruz</a>',
            $this->get('/users')->assertOk()->getContent()
        );
    }

    // ------------------------------------------------------------- the guards

    /**
     * The rankings are given to a department head, who does not hold
     * employees.view. A blue name would send them to a page that refuses.
     */
    public function test_a_head_gets_no_link_they_cannot_follow(): void
    {
        $head = $this->as('department-head');
        EmployeeProfile::factory()->create([
            'user_id' => $head->id, 'employee_no' => 'EMP-0003',
            'department_id' => $this->office->id,
            'position_id' => Position::factory()->create()->id,
        ]);
        $this->office->update(['head_user_id' => $head->id]);
        $this->application->update(['status' => 'approved', 'decided_at' => now()]);

        $html = $this->get('/rankings')->assertOk()->getContent();

        $this->assertStringContainsString('Juan Dela Cruz', $html, 'the head cannot see their own office');
        $this->assertStringNotContainsString('employees/', $html,
            'a head is offered a link to a page that would refuse them');
        $this->get(route('employees.show', $this->employee))->assertForbidden();
    }

    /** An archived account has no edit page, so its name is not a link. */
    public function test_an_archived_account_keeps_a_plain_name(): void
    {
        $this->as('system-admin');
        $this->employee->delete();

        $html = $this->get('/users?show=archived')->assertOk()->getContent();

        $this->assertStringContainsString('Juan Dela Cruz', $html);
        $this->assertStringNotContainsString(
            '<a href="'.route('users.edit', $this->employee).'"', $html,
            'an archived account offers an edit link it cannot honour'
        );
    }
}
