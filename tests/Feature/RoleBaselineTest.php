<?php

namespace Tests\Feature;

use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Each of the five roles arrives with what it needs, and nothing else.
 *
 * The gap was the baseline. Everybody on the payroll files leave, whatever
 * else they do — but only Employee carried leave.apply, leave.view-own and
 * leave.cancel. A Department Head assigned only that role could not file an
 * application, see one, or cancel one, which is exactly the workflow the LGU
 * described: the head applies and the Mayor and HR decide.
 *
 * It never showed because the demo accounts happened to hold Employee as well.
 */
class RoleBaselineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
    }

    /** @return array<string,array{0:string}> */
    public static function workflowRoles(): array
    {
        return [
            'employee' => ['employee'],
            'department head' => ['department-head'],
            'hr' => ['hr'],
            'mayor' => ['mayor'],
        ];
    }

    /**
     * @dataProvider workflowRoles
     */
    public function test_everybody_on_the_payroll_can_file_their_own_leave(string $slug): void
    {
        $user = $this->makeUser($slug);

        foreach (['dashboard.view', 'leave.apply', 'leave.view-own', 'leave.cancel'] as $permission) {
            $this->assertTrue($user->hasPermission($permission),
                $slug.' cannot '.$permission.' — they are an employee before they are anything else');
        }
    }

    /**
     * @dataProvider workflowRoles
     *
     * The permission is one thing; the page answering is another.
     */
    public function test_the_leave_pages_actually_open_for_them(string $slug): void
    {
        $this->actingAs($this->makeUser($slug));
        session(['otp_verified' => true]);

        $this->get('/leave')->assertOk();
        $this->get('/leave/apply')->assertOk();
        $this->get('/dashboard')->assertSuccessful();
    }

    // ------------------------------------------------------- and nothing more

    /** An employee holds their own leave and not one thing beyond it. */
    public function test_an_employee_is_given_only_their_own_leave(): void
    {
        $employee = $this->makeUser('employee');

        foreach ([
            'leave.requests.view-all', 'leave.approve.final', 'leave.review.department',
            'leave.certify.hr', 'employees.view', 'users.manage', 'rbac.manage',
            'security.dashboard', 'reports.generate',
        ] as $permission) {
            $this->assertFalse($employee->hasPermission($permission),
                'an employee holds '.$permission);
        }
    }

    /**
     * A head recommends for their own office and decides nothing. The scope is
     * enforced by the department they head, not by the permission alone.
     */
    public function test_a_department_head_recommends_and_does_not_decide(): void
    {
        $head = $this->makeUser('department-head');

        $this->assertTrue($head->hasPermission('leave.review.department'));
        $this->assertFalse($head->hasPermission('leave.approve.final'),
            'a head can decide any office\'s leave, not just recommend their own');
        $this->assertFalse($head->hasPermission('leave.certify.hr'));
    }

    /** The two authorised approvers, and only those two. */
    public function test_only_hr_and_the_mayor_can_decide(): void
    {
        foreach (['hr', 'mayor'] as $slug) {
            $this->assertTrue($this->makeUser($slug)->hasPermission('leave.approve.final'), $slug);
        }
        foreach (['employee', 'department-head', 'system-admin'] as $slug) {
            $this->assertFalse($this->makeUser($slug)->hasPermission('leave.approve.final'), $slug);
        }
    }

    /** Operating the system is not the same as working in it. */
    public function test_the_administrator_runs_the_system_rather_than_filing_leave(): void
    {
        $admin = $this->makeUser('system-admin');

        $this->assertTrue($admin->hasPermission('users.manage'));
        $this->assertTrue($admin->hasPermission('security.dashboard'));

        $this->assertFalse($admin->hasPermission('leave.apply'),
            'the administrator account files leave; its dashboard carries no leave figures');
        $this->assertFalse($admin->hasPermission('leave.approve.final'));
        $this->assertFalse($admin->hasPermission('employees.view-salary'));
    }

    /**
     * The Mayor decides applications, so the Mayor can look at the figures.
     *
     * reports.generate is the right to run reports, not the right to read what
     * is in them: each report names the permission its subject needs, checked
     * again in ReportController. So this opens the leave reports and none of
     * the security ones.
     */
    public function test_the_mayor_can_run_the_leave_reports_and_not_the_security_ones(): void
    {
        $mayor = $this->makeUser('mayor');

        $this->assertTrue($mayor->hasPermission('reports.generate'));
        $this->assertFalse($mayor->hasPermission('reports.security'));

        $visible = \App\Services\Reports\ReportService::visibleTo($mayor);

        $this->assertArrayHasKey('leave', $visible, 'the Mayor sees no leave reports');
        $this->assertArrayNotHasKey('security', $visible,
            'the Mayor can open the intrusion and audit reports');
        $this->assertCount(6, $visible['leave']);
    }

    /** And the page answers, rather than 403-ing on the way in. */
    public function test_the_reports_page_opens_for_the_mayor(): void
    {
        $this->actingAs($this->makeUser('mayor'));
        session(['otp_verified' => true]);

        $this->get('/reports')->assertOk()
            ->assertSee('Employee Leave Report')
            ->assertDontSee('Intrusion Report');
    }

    /**
     * A head is scoped to their own office, and every report in the catalogue
     * is LGU-wide. reports.generate on its own would give them a nav item to
     * an empty page; the permission the reports need would give them every
     * office's applications, which is the thing the scoping exists to prevent.
     */
    public function test_a_department_head_is_not_given_reports(): void
    {
        $head = $this->makeUser('department-head');

        $this->assertFalse($head->hasPermission('reports.generate'));
        $this->assertFalse($head->hasPermission('leave.requests.view-all'),
            'a head can see every office\'s applications, not just their own');
        $this->assertSame([], \App\Services\Reports\ReportService::visibleTo($head));
    }

    /** Nothing holds a permission that satisfies every check. */
    public function test_no_role_holds_everything(): void
    {
        foreach (Role::all() as $role) {
            $this->assertNotContains('*', $role->permissions->pluck('slug')->all(),
                $role->slug.' holds the wildcard');
        }
    }

    /** Reseeding must not quietly widen or narrow what a role holds. */
    public function test_the_grants_are_the_same_after_seeding_again(): void
    {
        $before = Role::with('permissions')->get()
            ->mapWithKeys(fn ($r) => [$r->slug => $r->permissions->pluck('slug')->sort()->values()->all()]);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $after = Role::with('permissions')->get()
            ->mapWithKeys(fn ($r) => [$r->slug => $r->permissions->pluck('slug')->sort()->values()->all()]);

        $this->assertSame($before->all(), $after->all());
    }
}
