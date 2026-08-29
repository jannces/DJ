<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The list toolbars.
 *
 * Every list had its own arrangement: a filter card floating above the
 * container on seven pages, a pair of buttons inside it on one, and nothing at
 * all on two whose controllers already accepted filters nobody could reach.
 * They are one shape now — search box and dropdowns inside the container,
 * above the rows they narrow.
 *
 * The live behaviour is script; the form underneath is not, and that is what
 * these check. A filter that only works with JavaScript is a filter that stops
 * working the first time the script fails to load.
 */
class ListFilterTest extends TestCase
{
    use RefreshDatabase;

    /** Every list that carries a toolbar, with the role that may see it. */
    public static function lists(): array
    {
        return [
            'users' => ['system-admin', '/users'],
            'devices' => ['system-admin', '/devices'],
            'audit logs' => ['system-admin', '/audit-logs'],
            'activity logs' => ['system-admin', '/activity-logs'],
            'intrusion logs' => ['system-admin', '/security/intrusions'],
            'employees' => ['hr', '/employees'],
            'balances' => ['hr', '/balances'],
            'all leave' => ['hr', '/all-leave'],
            'my leave' => ['employee', '/leave'],
        ];
    }

    private function signIn(string $role): User
    {
        $user = $this->makeUser($role);
        $this->actingAs($user);
        session(['otp_verified' => true]);

        return $user;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        SystemSetting::set('security.device_enforcement', false);
    }

    /**
     * @dataProvider lists
     */
    public function test_the_toolbar_is_inside_the_container(string $role, string $url): void
    {
        $this->signIn($role);

        $html = $this->get($url)->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '#<div class="card">\s*<form [^>]*class="list-toolbar"#', $html,
            $url.' keeps its filters outside the list container'
        );
        $this->assertStringNotContainsString('card card-body mb-3', $html,
            $url.' still has the old floating filter card');
    }

    /**
     * @dataProvider lists
     */
    public function test_the_rows_are_findable_for_the_script_to_swap(string $role, string $url): void
    {
        $this->signIn($role);

        $html = $this->get($url)->assertOk()->getContent();

        $this->assertStringContainsString('<div data-list>', $html,
            $url.' has nothing for the script to replace');
        $this->assertStringContainsString('<table',
            substr($html, strpos($html, '<div data-list>')));
    }

    /**
     * The pager has to be swapped with the rows. Swapping the table but not
     * the pager would leave page numbers describing a list that is no longer
     * there — "1 2 3" over a filtered result that fits on one page.
     *
     * Checked in the sources because an empty list renders no pager at all,
     * so the running page cannot show whether it would be in the right place.
     */
    public function test_the_pager_is_inside_the_part_that_gets_swapped(): void
    {
        $offenders = [];

        foreach (glob(resource_path('views/**/*.blade.php')) as $file) {
            $source = file_get_contents($file);
            if (! str_contains($source, '<div data-list>')) {
                continue;
            }
            if (strpos($source, 'links()') < strpos($source, '<div data-list>')) {
                $offenders[] = basename($file);
            }
        }

        $this->assertNotEmpty(glob(resource_path('views/**/*.blade.php')));
        $this->assertSame([], $offenders,
            'these page outside the part the script replaces');
    }

    /**
     * @dataProvider lists
     *
     * The submit button is in the markup and the script removes it, so the
     * page keeps working when the script does not run.
     */
    public function test_the_toolbar_still_submits_without_the_script(string $role, string $url): void
    {
        $this->signIn($role);

        $html = $this->get($url)->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<form method="GET"#', $html,
            $url.'\'s toolbar is not a real form');
        $this->assertStringContainsString('toolbar-submit', $html,
            $url.' cannot be filtered at all without JavaScript');
    }

    // ------------------------------------------------ the filters themselves

    public function test_users_can_be_asked_by_role(): void
    {
        $this->signIn('system-admin');
        $head = $this->makeUser('department-head');
        $head->update(['name' => 'Perla Domingo']);

        $clerk = $this->makeUser('employee');
        $clerk->update(['name' => 'Ramon Bautista']);

        $html = $this->get('/users?role=department-head')->assertOk()->getContent();

        $this->assertStringContainsString('Perla Domingo', $html);
        $this->assertStringNotContainsString('Ramon Bautista', $html);

        $other = $this->get('/users?role=mayor')->assertOk()->getContent();
        $this->assertStringNotContainsString('Perla Domingo', $other);
    }

    /** Archived was on-or-off, so current and archived could not be seen together. */
    public function test_users_show_offers_current_archived_and_all(): void
    {
        $this->signIn('system-admin');
        $gone = $this->makeUser('employee');
        $gone->update(['name' => 'Retired Clerk']);
        $here = $this->makeUser('employee');
        $here->update(['name' => 'Serving Clerk']);
        $gone->delete();

        $this->get('/users')->assertOk()->assertDontSee('Retired Clerk');
        $this->get('/users?show=archived')->assertOk()
            ->assertSee('Retired Clerk')->assertDontSee('Serving Clerk');
        $this->get('/users?show=all')->assertOk()
            ->assertSee('Retired Clerk')->assertSee('Serving Clerk');
    }

    public function test_employees_can_be_asked_by_position(): void
    {
        $this->signIn('hr');
        $treasurer = Position::create(['title' => 'Municipal Treasurer', 'salary_grade' => 'SG 24']);
        $aide = Position::create(['title' => 'Administrative Aide I', 'salary_grade' => 'SG 1']);
        $department = Department::create(['name' => 'Treasury', 'code' => 'MTO']);

        foreach ([[$treasurer, 'Ana Reyes'], [$aide, 'Ben Cruz']] as [$position, $name]) {
            $user = $this->makeUser('employee');
            $user->update(['name' => $name]);
            \App\Models\EmployeeProfile::factory()->create([
                'user_id' => $user->id,
                'department_id' => $department->id,
                'position_id' => $position->id,
            ]);
        }

        $html = $this->get('/employees?position='.$treasurer->id)->assertOk()->getContent();

        $this->assertStringContainsString('Ana Reyes', $html);
        $this->assertStringNotContainsString('Ben Cruz', $html);
    }

    public function test_all_leave_can_be_searched_by_reference(): void
    {
        $user = $this->signIn('hr');
        $type = LeaveType::where('code', 'VL')->firstOrFail();

        $wanted = LeaveRequest::factory()->create([
            'user_id' => $user->id, 'leave_type_id' => $type->id, 'reference_no' => 'LR-2026-0042',
        ]);
        LeaveRequest::factory()->create([
            'user_id' => $user->id, 'leave_type_id' => $type->id, 'reference_no' => 'LR-2026-0099',
        ]);

        $html = $this->get('/all-leave?q=0042')->assertOk()->getContent();

        $this->assertStringContainsString($wanted->reference_no, $html);
        $this->assertStringNotContainsString('LR-2026-0099', $html);
    }

    /**
     * The controller has always accepted ?status=; nothing on the page ever
     * offered it, so you had to know to type it into the address bar.
     */
    public function test_my_leave_requests_now_offers_the_status_filter(): void
    {
        $this->signIn('employee');

        $html = $this->get('/leave')->assertOk()->getContent();

        $this->assertStringContainsString('name="status"', $html);
        $this->assertStringContainsString('Status: Any', $html);
    }

    public function test_activity_logs_can_be_asked_by_method(): void
    {
        $this->signIn('system-admin');

        $html = $this->get('/activity-logs')->assertOk()->getContent();

        $this->assertStringContainsString('name="method"', $html);
        $this->assertStringContainsString('>POST<', $html);
    }

    /** Actions come from what is in the log, not from a box you must guess. */
    public function test_audit_actions_are_a_list_of_what_is_actually_there(): void
    {
        $this->signIn('system-admin');
        \App\Models\AuditLog::create([
            'user_id' => null, 'action' => 'settings_updated', 'ip' => '127.0.0.1',
        ]);

        $html = $this->get('/audit-logs')->assertOk()->getContent();

        $this->assertStringContainsString('value="settings_updated"', $html);
    }

    /** A bookmark written against the old intrusion filter still works. */
    public function test_the_old_intrusion_ip_parameter_still_works(): void
    {
        $this->signIn('system-admin');
        \App\Models\IntrusionLog::create([
            'category' => 'sqli', 'severity' => 'high', 'route' => 'employees',
            'method' => 'GET', 'payload_excerpt' => 'x', 'matched_rule' => 'sqli_signature',
            'ip' => '192.168.1.77',
        ]);

        $this->get('/security/intrusions?ip=192.168.1.77')->assertOk()->assertSee('192.168.1.77');
    }

    /** Clear only appears when something is actually filtering. */
    public function test_clear_shows_up_only_when_there_is_something_to_clear(): void
    {
        $this->signIn('system-admin');

        $this->get('/users')->assertOk()->assertDontSee('toolbar-clear');
        $this->get('/users?status=active')->assertOk()->assertSee('toolbar-clear');
        // Paging is not filtering.
        $this->get('/users?page=2')->assertOk()->assertDontSee('toolbar-clear');
    }
}
