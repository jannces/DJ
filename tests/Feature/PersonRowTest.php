<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\EmployeeProfile;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A person looks the same wherever the system lists one.
 *
 * The rankings had initials in a coloured disc; the employee list had a bold
 * name and an address. Same people, two designs, and the only way to give the
 * employee list the better one was to borrow classes named `rk-` after a page
 * it is not on. Both now render the same component.
 *
 * The colour is keyed off the NAME rather than the row's position, which is
 * what makes "the same" true: position meant a person was orange on one page
 * and green on the other, and changed colour whenever a filter reordered the
 * list.
 */
class PersonRowTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();

        $office = Department::create(['name' => 'Sangguniang Bayan Office', 'code' => 'SB']);
        $vl = LeaveType::where('code', 'VL')->firstOrFail();

        $this->employee = $this->makeUser('employee');
        $this->employee->update(['name' => 'Dj Robin Mendoza', 'email' => 'djrobin@example.test']);
        EmployeeProfile::factory()->create([
            'user_id' => $this->employee->id, 'employee_no' => 'EMP-0001',
            'first_name' => 'Dj Robin', 'last_name' => 'Mendoza',
            'department_id' => $office->id, 'position_id' => Position::factory()->create()->id,
        ]);
        LeaveBalance::create([
            'user_id' => $this->employee->id, 'leave_type_id' => $vl->id,
            'earned' => 15, 'used' => 3, 'balance' => 12,
        ]);
        LeaveRequest::factory()->create([
            'user_id' => $this->employee->id, 'leave_type_id' => $vl->id,
            'status' => 'approved', 'working_days' => 3,
            'date_filed' => now()->subDays(20)->toDateString(),
            'start_date' => now()->subDays(10)->toDateString(),
            'end_date' => now()->subDays(8)->toDateString(),
            'decided_at' => now()->subDays(15),
        ]);
    }

    private function as(string $role): void
    {
        $user = $this->makeUser($role);
        $this->actingAs($user);
        session(['otp_verified' => true]);
    }

    public function test_the_employee_list_draws_the_person_the_way_the_rankings_do(): void
    {
        $this->as('hr');

        $html = $this->get('/employees')->assertOk()->getContent();

        $this->assertStringContainsString('class="person"', $html);
        $this->assertMatchesRegularExpression(
            '/<span class="person-av" data-n="\d"[^>]*>DM<\/span>/', $html,
            'the employee list has no initials disc');
        $this->assertStringContainsString('class="person-sub">djrobin@example.test', $html,
            'the address no longer sits under the name');

        // The name is still the way in, which is what it was before the disc.
        $this->assertStringContainsString(
            '<a href="'.route('employees.show', $this->employee).'" class="person-name name-link">Dj Robin Mendoza</a>',
            $html);
    }

    /**
     * Every list ABOUT PEOPLE draws the same row.
     *
     * Four of them: employees, rankings, user accounts and leave balances. The
     * leave lists are deliberately absent -- those rows are about an
     * application, the name there points at the request rather than the
     * person, and a disc would compete with the reference number for the eye.
     */
    public function test_all_four_people_lists_draw_the_shared_row(): void
    {
        foreach (['/employees' => 'hr', '/rankings' => 'hr',
            '/balances' => 'hr', '/users' => 'system-admin'] as $url => $role) {
            $this->as($role);

            $this->assertMatchesRegularExpression(
                '/<span class="person-av" data-n="\d"[^>]*>DM<\/span>/',
                $this->get($url)->assertOk()->getContent(),
                $url.' does not draw the shared person row');
        }
    }

    /**
     * Balances shows no second line.
     *
     * Its subject is numbers, and a column of email addresses on a page you
     * scan for VL and SL figures works against the thing it is for. The
     * component takes a missing `sub` and draws none -- no special case.
     */
    public function test_the_balances_list_carries_no_second_line(): void
    {
        $this->as('hr');

        $html = $this->get('/balances')->assertOk()->getContent();

        $this->assertStringContainsString('class="person-av"', $html);
        $this->assertStringNotContainsString('person-sub', $html);
    }

    /**
     * An archived account keeps the disc and loses the link.
     *
     * It has no edit page to open, so a blue name would refuse -- but the
     * person is still a person, and dropping the disc would make the archived
     * list the one place that looks different.
     */
    public function test_an_archived_account_keeps_the_disc_without_a_link(): void
    {
        $this->as('system-admin');
        $this->employee->delete();

        $html = $this->get('/users?show=archived')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<span class="person-av" data-n="\d"[^>]*>DM<\/span>/', $html);
        $this->assertStringContainsString('<span class="person-name">Dj Robin Mendoza</span>', $html);
        $this->assertStringNotContainsString(
            '<a href="'.route('users.edit', $this->employee).'"', $html,
            'an archived account offers an edit link it cannot honour');
    }

    /**
     * One person, one colour, on both pages.
     *
     * This is the assertion that would fail if either page went back to
     * colouring by row position.
     */
    public function test_a_person_keeps_the_same_colour_on_both_pages(): void
    {
        $this->as('hr');

        $shade = function (string $url): string {
            $html = $this->get($url)->assertOk()->getContent();
            preg_match('/<span class="person-av" data-n="(\d)"[^>]*>DM</', $html, $m);
            $this->assertNotEmpty($m, "no person disc on $url");

            return $m[1];
        };

        $this->assertSame($shade('/employees'), $shade('/rankings'),
            'the same employee is a different colour on the two lists');
    }

    /** Initials are the first and last name, not the first two words. */
    public function test_the_initials_are_the_first_and_last_name(): void
    {
        $this->employee->update(['name' => 'Ma. Leonora M. Gulla']);
        $this->as('hr');

        $this->assertMatchesRegularExpression(
            '/<span class="person-av" data-n="\d"[^>]*>MG<\/span>/',
            $this->get('/employees')->assertOk()->getContent());
    }

    /**
     * The disc is decoration and says so.
     *
     * A screen reader announcing "D M, Dj Robin Mendoza" reads the name twice
     * in a row, the second time as two letters.
     */
    public function test_the_disc_is_hidden_from_assistive_technology(): void
    {
        $this->as('hr');

        $this->assertStringContainsString('<span class="person-av" data-n="', $this->get('/employees')->getContent());
        $this->assertMatchesRegularExpression(
            '/<span class="person-av"[^>]*aria-hidden="true"/',
            $this->get('/employees')->assertOk()->getContent());
    }

    /**
     * A department head reaches the rankings but does not hold employees.view.
     * They get the row without a link, rather than a blue name leading to a 403.
     */
    public function test_a_head_gets_the_row_without_a_link_they_cannot_follow(): void
    {
        $head = $this->makeUser('department-head');
        EmployeeProfile::factory()->create([
            'user_id' => $head->id, 'employee_no' => 'EMP-0002',
            'department_id' => $this->employee->employeeProfile->department_id,
            'position_id' => Position::factory()->create()->id,
        ]);
        $this->employee->employeeProfile->department->update(['head_user_id' => $head->id]);

        $this->actingAs($head);
        session(['otp_verified' => true]);

        $html = $this->get('/rankings')->assertOk()->getContent();

        $this->assertStringContainsString('<span class="person-name">Dj Robin Mendoza</span>', $html);
        $this->assertStringNotContainsString('employees/', $html,
            'a head is offered a link to a page that would refuse them');
    }

    /** The `rk-` classes the employee page would have had to borrow are gone. */
    public function test_the_page_specific_classes_are_not_reintroduced(): void
    {
        $css = file_get_contents(public_path('css/app.css'));

        foreach (['.rk-who', '.rk-av', '.rk-name'] as $selector) {
            $this->assertStringNotContainsString($selector, $css,
                "$selector is back, so a second page can only copy it");
        }
    }
}
