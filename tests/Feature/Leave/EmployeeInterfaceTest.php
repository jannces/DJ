<?php

namespace Tests\Feature\Leave;

use App\Models\Department;
use App\Models\EmployeeProfile;
use App\Models\LeaveBalance;
use App\Models\LeaveHistory;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards the employee-side restrictions: no global search, no CSV export, and
 * leave credits shown on the dashboard rather than a separate page. Each denial
 * is asserted against the ENDPOINT, not the markup — hiding a control in Blade
 * is not access control.
 */
class EmployeeInterfaceTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();

        $this->employee = $this->makeUser('employee');
        EmployeeProfile::factory()->create([
            'user_id' => $this->employee->id,
            'department_id' => Department::factory()->create()->id,
        ]);
    }

    private function asEmployee(): self
    {
        $this->actingAs($this->employee);
        session(['otp_verified' => true]);

        return $this;
    }

    // ---------------------------------------------------------------- search

    public function test_employee_cannot_reach_the_global_search_endpoint(): void
    {
        $this->asEmployee()->get('/search?q=cruz')->assertForbidden();
    }

    public function test_employee_dashboard_does_not_render_the_search_box(): void
    {
        $this->asEmployee()->get('/dashboard')
            ->assertOk()
            ->assertDontSee('Search employees, requests, departments');
    }

    public function test_hr_can_still_use_global_search(): void
    {
        $hr = $this->makeUser('hr');
        $this->actingAs($hr);
        session(['otp_verified' => true]);

        $this->get('/search?q=cruz')->assertOk();
    }

    public function test_system_administrator_can_still_use_global_search(): void
    {
        $admin = $this->makeUser('system-admin');
        $this->actingAs($admin);
        session(['otp_verified' => true]);

        $this->get('/search?q=cruz')->assertOk();
    }

    // ------------------------------------------------------------------- CSV

    public function test_employee_cannot_export_a_report_as_csv(): void
    {
        $this->asEmployee()->get('/reports/employee-leave?format=csv')->assertForbidden();
    }

    public function test_employee_cannot_reach_the_reports_index(): void
    {
        $this->asEmployee()->get('/reports')->assertForbidden();
    }

    public function test_denied_csv_attempt_is_recorded_as_a_privilege_probe(): void
    {
        $this->asEmployee()->get('/reports/employee-leave?format=csv')->assertForbidden();

        $this->assertDatabaseHas('intrusion_logs', [
            'category' => 'privilege',
            'user_id' => $this->employee->id,
        ]);
    }

    public function test_hr_can_still_export_a_report_as_csv(): void
    {
        $hr = $this->makeUser('hr');
        $this->actingAs($hr);
        session(['otp_verified' => true]);

        $this->get('/reports/employee-leave?format=csv')
            ->assertOk()
            ->assertHeader('content-disposition');
    }

    // -------------------------------------------------------- balances moved

    public function test_the_old_my_balances_route_no_longer_exists(): void
    {
        $this->asEmployee()->get('/leave/balances')->assertNotFound();
    }

    public function test_dashboard_shows_the_employees_own_balances_and_credit_history(): void
    {
        $vl = LeaveType::where('code', 'VL')->first();
        LeaveBalance::create([
            'user_id' => $this->employee->id, 'leave_type_id' => $vl->id,
            'earned' => 15, 'used' => 2, 'balance' => 13,
        ]);
        LeaveHistory::create([
            'user_id' => $this->employee->id, 'leave_type_id' => $vl->id,
            'entry_type' => 'deduction', 'days' => -2, 'balance_after' => 13,
            'remarks' => 'Approved Vacation Leave (LV-TEST-1)',
        ]);

        $this->asEmployee()->get('/dashboard')
            ->assertOk()
            ->assertSee('My Leave Balances')
            ->assertSee('Credit History')
            ->assertSee('13.00')
            ->assertSee('Approved Vacation Leave (LV-TEST-1)');
    }

    public function test_dashboard_never_shows_another_employees_credit_history(): void
    {
        $other = $this->makeUser('employee');
        $vl = LeaveType::where('code', 'VL')->first();
        LeaveHistory::create([
            'user_id' => $other->id, 'leave_type_id' => $vl->id,
            'entry_type' => 'deduction', 'days' => -4, 'balance_after' => 11,
            'remarks' => 'SOMEONE ELSES LEAVE',
        ]);

        $this->asEmployee()->get('/dashboard')
            ->assertOk()
            ->assertDontSee('SOMEONE ELSES LEAVE');
    }

    // ------------------------------------------------------- application form

    public function test_apply_page_renders_the_official_form_from_database_leave_types(): void
    {
        $this->asEmployee()->get('/leave/apply')
            ->assertOk()
            ->assertSee('APPLICATION FOR LEAVE')
            ->assertSee('6.A TYPE OF LEAVE TO BE AVAILED OF')
            ->assertSee('6.D COMMUTATION')
            // Instructions moved to their own sidebar page.
            ->assertDontSee('INSTRUCTIONS AND REQUIREMENTS')
            // Types come from the database, not a hardcoded list.
            ->assertSee('Vacation Leave')
            ->assertSee('Special Leave Benefits for Women')
            // Section 7 is present but carries no employee-editable inputs.
            ->assertSee('7.A CERTIFICATION OF LEAVE CREDITS')
            ->assertSee('7.C APPROVED FOR');
    }

    public function test_a_custom_leave_type_appears_on_the_form_without_a_code_change(): void
    {
        LeaveType::create([
            'code' => 'ZZZ', 'name' => 'Locally Negotiated Leave', 'category' => 'special',
            'deductible' => false, 'active' => true, 'is_custom' => true,
        ]);

        $this->asEmployee()->get('/leave/apply')->assertOk()->assertSee('Locally Negotiated Leave');
    }

    public function test_blank_detail_fields_are_not_stored(): void
    {
        $vl = LeaveType::where('code', 'VL')->first();
        LeaveBalance::create([
            'user_id' => $this->employee->id, 'leave_type_id' => $vl->id,
            'earned' => 15, 'used' => 0, 'balance' => 15,
        ]);

        // The official layout posts every "In case of…" blank; only the filled
        // ones belong in leave_requests.details.
        $this->asEmployee()->post('/leave', [
            'leave_type_id' => [$vl->id],
            'date_filed' => now()->toDateString(),
            'start_date' => now()->addWeek()->next('Monday')->toDateString(),
            'end_date' => now()->addWeek()->next('Monday')->toDateString(),
            'commutation' => '0',
            'applicant_signature' => $this->employee->name,
            'details' => [
                'location' => 'within_ph',
                'location_specify' => 'Alicia, Isabela',
                'illness' => '',
                'confinement' => '',
                'purpose_other' => '',
                'calamity' => '',
            ],
        ])->assertRedirect();

        $stored = \App\Models\LeaveRequest::where('user_id', $this->employee->id)->firstOrFail();
        $this->assertSame(
            ['location' => 'within_ph', 'location_specify' => 'Alicia, Isabela'],
            $stored->details,
        );
    }

    public function test_leave_types_are_uncheckable_checkboxes_not_locked_controls(): void
    {
        $response = $this->asEmployee()->get('/leave/apply');

        $response->assertOk();
        // Real checkboxes, so any option can be cleared again...
        $response->assertSee('type="checkbox" name="leave_type_id[]"', false);
        // ...and nothing is pre-ticked on a new application.
        $response->assertDontSee('name="leave_type_id[]" value="'.LeaveType::where('code', 'VL')->value('id').'" checked', false);
    }

    public function test_selecting_two_leave_types_is_refused(): void
    {
        $vl = LeaveType::where('code', 'VL')->first();
        $sl = LeaveType::where('code', 'SL')->first();

        $this->asEmployee()->post('/leave', [
            'leave_type_id' => [$vl->id, $sl->id],
            'date_filed' => now()->toDateString(),
            'start_date' => now()->addWeek()->next('Monday')->toDateString(),
            'end_date' => now()->addWeek()->next('Monday')->toDateString(),
            'commutation' => '0',
            'applicant_signature' => $this->employee->name,
        ])->assertSessionHasErrors('leave_type_id');

        $this->assertDatabaseCount('leave_requests', 0);
    }

    public function test_submitting_with_no_leave_type_is_refused(): void
    {
        $this->asEmployee()->post('/leave', [
            'date_filed' => now()->toDateString(),
            'start_date' => now()->addWeek()->next('Monday')->toDateString(),
            'end_date' => now()->addWeek()->next('Monday')->toDateString(),
            'commutation' => '0',
            'applicant_signature' => $this->employee->name,
        ])->assertSessionHasErrors('leave_type_id');
    }

    public function test_instructions_page_is_reachable_from_its_own_route(): void
    {
        $this->asEmployee()->get('/leave-instructions')
            ->assertOk()
            ->assertSee('Instructions and Requirements')
            ->assertSee('Vacation leave')
            ->assertSee('Adoption Leave')
            ->assertSee('Monetization of leave credits');
    }

    /**
     * "Apply for Leave" must appear exactly once per page — the sidebar link.
     * A second occurrence means a duplicate shortcut has crept back into the
     * page body, which is precisely what was removed.
     */
    public function test_dashboard_offers_apply_for_leave_only_once(): void
    {
        $html = $this->asEmployee()->get('/dashboard')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'Apply for Leave'));
    }

    public function test_my_leave_requests_offers_apply_for_leave_only_once(): void
    {
        $html = $this->asEmployee()->get('/leave')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'Apply for Leave'));
    }

    public function test_an_employee_cannot_file_leave_in_another_employees_name(): void
    {
        $victim = $this->makeUser('employee');
        $vl = LeaveType::where('code', 'VL')->first();
        LeaveBalance::create([
            'user_id' => $this->employee->id, 'leave_type_id' => $vl->id,
            'earned' => 15, 'used' => 0, 'balance' => 15,
        ]);

        // user_id is never read from the request — the session owns it.
        $this->asEmployee()->post('/leave', [
            'user_id' => $victim->id,
            'leave_type_id' => [$vl->id],
            'date_filed' => now()->toDateString(),
            'start_date' => now()->addWeek()->next('Monday')->toDateString(),
            'end_date' => now()->addWeek()->next('Monday')->toDateString(),
            'commutation' => '0',
            'applicant_signature' => $this->employee->name,
            'details' => ['location' => 'within_ph', 'location_specify' => 'Alicia'],
        ])->assertRedirect();

        $this->assertDatabaseMissing('leave_requests', ['user_id' => $victim->id]);
        $this->assertDatabaseHas('leave_requests', ['user_id' => $this->employee->id]);
    }
}
