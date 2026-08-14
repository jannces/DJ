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
            // Balance leads the KPI strip.
            ->assertSee('Vacation Leave')
            ->assertSee('13.00')
            // Credit summary panel and the history table below it.
            ->assertSee('Credit summary')
            ->assertSee('Credit history')
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
            // The instructions sheet is a separate page reached from the header
            // button; it is not appended to the form as a second printed page.
            ->assertDontSee('INSTRUCTIONS AND REQUIREMENTS')
            // Types come from the database, not a hardcoded list.
            ->assertSee('Vacation Leave')
            ->assertSee('Special Leave Benefits for Women')
            // Section 7 is drawn in full to follow the official sheet.
            ->assertSee('7. DETAILS OF ACTION ON APPLICATION')
            ->assertSee('7.A CERTIFICATION OF LEAVE CREDITS')
            ->assertSee('7.B RECOMMENDATION')
            ->assertSee('7.C APPROVED FOR:')
            ->assertSee('7.D DISAPPROVED DUE TO:');
    }

    /**
     * Section 7 belongs to the approving officer. It is rendered for fidelity
     * with the paper form but must contain no control the applicant could use.
     */
    public function test_section_seven_on_the_entry_form_has_no_inputs(): void
    {
        $html = $this->asEmployee()->get('/leave/apply')->assertOk()->getContent();

        $section = substr(
            $html,
            $start = strpos($html, '7. DETAILS OF ACTION ON APPLICATION'),
            strpos($html, 'SUPPORTING DOCUMENTS') - $start,
        );

        $this->assertStringNotContainsString('<input', $section);
        $this->assertStringNotContainsString('<select', $section);
        $this->assertStringNotContainsString('<textarea', $section);
    }

    /** The submitted-form preview draws section 7 filled in. */
    public function test_form_preview_draws_section_seven_in_full(): void
    {
        $vl = LeaveType::where('code', 'VL')->first();
        LeaveBalance::create([
            'user_id' => $this->employee->id, 'leave_type_id' => $vl->id,
            'earned' => 15, 'used' => 0, 'balance' => 15,
        ]);
        $this->asEmployee()->post('/leave', [
            'leave_type_id' => [$vl->id],
            'date_filed' => now()->toDateString(),
            'start_date' => now()->addWeek()->next('Monday')->toDateString(),
            'end_date' => now()->addWeek()->next('Monday')->toDateString(),
            'commutation' => '0',
            'applicant_signature' => $this->employee->name,
            'details' => ['location' => 'within_ph', 'location_specify' => 'Alicia'],
        ])->assertRedirect();

        $request = \App\Models\LeaveRequest::where('user_id', $this->employee->id)->firstOrFail();

        $this->asEmployee()->get("/leave/{$request->id}/preview")
            ->assertOk()
            ->assertSee('7.A CERTIFICATION OF LEAVE CREDITS')
            ->assertSee('7.C APPROVED FOR:');
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

    public function test_instructions_are_reachable_from_the_application_form(): void
    {
        // The sidebar no longer carries an Instructions entry, so the form itself
        // is how an applicant reaches the documentary requirements.
        $this->asEmployee()->get('/leave/apply')
            ->assertOk()
            ->assertSee('Instructions and Requirements')
            ->assertSee(route('leave.instructions'), false);
    }

    public function test_the_employee_sidebar_carries_only_the_three_leave_entries(): void
    {
        $html = $this->asEmployee()->get('/dashboard')->assertOk()->getContent();

        // Assert against the SIDEBAR only. Change password and Notifications are
        // deliberately still reachable from the top-bar profile menu, so testing
        // the whole page would contradict the design.
        $sidebar = substr(
            $html,
            $start = strpos($html, '<nav class="lms-sidebar'),
            strpos($html, '</nav>', $start) - $start,
        );

        foreach (['Dashboard', 'Apply for Leave', 'My Leave Requests'] as $entry) {
            $this->assertStringContainsString($entry, $sidebar);
        }

        // Nothing from the retired Information section or the footer block.
        $this->assertStringNotContainsString('Instructions and Requirements', $sidebar);
        $this->assertStringNotContainsString('Change password', $sidebar);
        $this->assertStringNotContainsString('Notifications', $sidebar);

        // An employee gets no administrator sections either.
        foreach (['Leave Approvals', 'Employees', 'Reports', 'System Settings'] as $adminEntry) {
            $this->assertStringNotContainsString($adminEntry, $sidebar);
        }
    }

    public function test_the_application_form_has_no_zoom_or_day_counter(): void
    {
        $html = $this->asEmployee()->get('/leave/apply')->assertOk()->getContent();

        $this->assertStringNotContainsString('data-csc-zoom', $html);
        $this->assertStringNotContainsString('Count working days', $html);
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
