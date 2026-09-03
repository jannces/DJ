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

    // --------------------------------------------------------------- exports

    /**
     * CSV has since been dropped as a format — `?format=csv` now falls through
     * to the on-screen view. These stay exactly as they were, because the rule
     * they encode is about the *route*, not the format: an export an account
     * may not have is refused by the server rather than hidden in the markup,
     * and that has to hold for a format nobody links to any more.
     */
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

    public function test_hr_can_still_export_a_report(): void
    {
        $hr = $this->makeUser('hr');
        $this->actingAs($hr);
        session(['otp_verified' => true]);

        // Excel and PDF are the two formats now; CSV was dropped from the
        // backend as well as the button, so this is what "still exports" means.
        foreach (['xlsx', 'pdf'] as $format) {
            $this->get('/reports/employee-leave?format='.$format)
                ->assertOk()
                ->assertHeader('content-disposition');
        }
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
            // Balances surface as the credit bars and the ledger, not as one
            // KPI card per leave type.
            ->assertSee('Credits remaining, by type')
            ->assertSee('Vacation Leave')
            ->assertSee('13 left')
            ->assertSee('Credit history')
            ->assertSee('13.00')
            ->assertSee('Approved Vacation Leave (LV-TEST-1)');
    }

    /**
     * The five states one credit bar has to survive.
     *
     * The last two are the ones that were wrong: a type with nothing accrued
     * divided by zero and rendered the bar NaN% wide, and "nothing left" used
     * to look identical to "no data" because both drew an empty grey track.
     */
    public function test_a_credit_bar_says_which_of_its_five_states_it_is_in(): void
    {
        $codes = ['VL' => [0, 15], 'SL' => [6, 9], 'SPL' => [2.5, 2.5], 'FL' => [5, 0], 'TL' => [0, 0]];

        foreach ($codes as $code => [$used, $left]) {
            LeaveBalance::create([
                'user_id' => $this->employee->id,
                'leave_type_id' => LeaveType::where('code', $code)->firstOrFail()->id,
                'earned' => $used + $left, 'used' => $used, 'balance' => $left,
            ]);
        }

        $html = $this->asEmployee()->get('/dashboard')->assertOk()->getContent();

        foreach (['ok', 'low', 'spent', 'none'] as $state) {
            $this->assertStringContainsString('data-state="'.$state.'"', $html,
                'no bar is in the '.$state.' state');
        }

        $this->assertStringNotContainsString('NaN', $html,
            'a type with no accrued credits is dividing by zero again');
        $this->assertStringContainsString('not accrued', $html,
            '"not accrued" is a different claim from "none left" and must say so');
        $this->assertStringContainsString('none left', $html);
    }

    public function test_employee_dashboard_shows_application_state_counters(): void
    {
        $vl = LeaveType::where('code', 'VL')->first();
        foreach (['pending', 'approved', 'rejected'] as $status) {
            \App\Models\LeaveRequest::factory()->create([
                'user_id' => $this->employee->id,
                'leave_type_id' => $vl->id,
                'status' => $status,
            ]);
        }

        $this->asEmployee()->get('/dashboard')
            ->assertOk()
            // Four counters, each answering something an employee about to file
            // would actually ask.
            ->assertSee('Vacation left')
            ->assertSee('Sick left')
            ->assertSee('Waiting on a decision')
            ->assertSee('Taken this year')
            // Nothing about anybody else's leave: this pane is gated on
            // leave.view-own and an employee holds nothing wider.
            ->assertDontSee('Applications by office')
            ->assertDontSee('Coverage risk')
            ->assertDontSee('Waiting longest');
    }

    public function test_employee_dashboard_draws_no_charts(): void
    {
        $html = $this->asEmployee()->get('/dashboard')->assertOk()->getContent();

        // "How many days do I have left" is a number. Plotting it would be
        // decoration, and the aggregate charts are somebody else's screen.
        foreach (['chartMain', 'chartMix', 'chartSpark', '<canvas', 'class="ln"', 'class="vb"'] as $absent) {
            $this->assertStringNotContainsString($absent, $html);
        }

        // The credit bars stay — they are a readout, not a chart.
        $this->assertStringContainsString('Credits remaining, by type', $html);
    }

    public function test_notifications_appear_once_in_the_top_bar(): void
    {
        $html = $this->asEmployee()->get('/dashboard')->assertOk()->getContent();

        // The bell icon remains the single entry point...
        $this->assertStringContainsString('aria-label="Notifications"', $html);
        // ...and the profile-menu duplicate is gone.
        $this->assertStringNotContainsString('me-2"></i>Notifications', $html);
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

    public function test_apply_page_collects_every_part_of_the_form(): void
    {
        $this->asEmployee()->get('/leave/apply')
            ->assertOk()
            ->assertSee('Application for Leave')
            ->assertSee('Civil Service Form No. 6')
            // The CSC box references (6.A, 7.B and the rest) were dropped from
            // the ENTRY form: they are a filing clerk's index, and an employee
            // choosing a leave type has no use for them. The printed sheet
            // still carries every one -- see PrintedFormTest.
            ->assertDontSee('6.A')
            ->assertDontSee('7.B')
            // Types come from the database, not a hardcoded list.
            ->assertSee('Vacation Leave')
            ->assertSee('Special Leave Benefits for Women')
            // The instructions sheet is a separate page reached from the header
            // button; it is not appended to the form as a second printed page.
            ->assertDontSee('INSTRUCTIONS AND REQUIREMENTS');
    }

    /**
     * The entry form is no longer a facsimile, but it must still collect every
     * value the official sheet carries. Each field is checked by the NAME it
     * posts under, which is what the controller and the policy engine read —
     * markup can be restyled freely, these cannot go missing.
     */
    public function test_the_form_still_collects_every_field_the_sheet_carries(): void
    {
        $html = $this->asEmployee()->get('/leave/apply')->assertOk()->getContent();

        foreach ([
            'leave_type_id[]', 'purpose', 'date_filed', 'start_date', 'end_date',
            // `commutation` is deliberately absent: box 6.D was dropped from
            // the entry form, though the column, its default and its
            // validation rule all remain -- see create.blade.php.
            'applicant_signature', 'late_filing_reason',
            'details[location]', 'details[location_specify]', 'details[travel_details]',
            'details[confinement]', 'details[illness]', 'details[surgery_details]',
            'details[purpose]', 'details[purpose_other]', 'details[expected_delivery]',
            'details[extension]', 'details[accident_details]',
            'details[calamity]', 'details[calamity_area]',
            'details[reason]', 'details[days_to_monetize]', 'details[separation_type]',
            'documents[supporting_document]', 'documents[medical_certificate]',
        ] as $field) {
            $this->assertStringContainsString('name="'.$field.'"', $html,
                "the form no longer collects: {$field}");
        }
    }

    /**
     * Every detail field a leave type marks REQUIRED must have an input on the
     * form. Special Privilege Leave was once impossible to file because its
     * required `travel_details` had no control anywhere — this fails if that
     * class of defect returns, for any type, including one an admin adds.
     */
    public function test_every_required_policy_field_has_an_input(): void
    {
        $html = $this->asEmployee()->get('/leave/apply')->assertOk()->getContent();

        foreach (LeaveType::active()->get() as $type) {
            foreach ($type->detail_schema ?? [] as $field) {
                if (! ($field['required'] ?? false)) {
                    continue;
                }
                $this->assertStringContainsString('name="details['.$field['name'].']"', $html,
                    "{$type->name} requires '{$field['name']}' but the form has no input for it");
            }
        }
    }

    /**
     * 6.B shows only the block belonging to the chosen leave type. The reveal
     * is CSS, so every control stays in the DOM — hiding is a screen
     * convenience and must never mean "not submitted". This asserts the wiring
     * the CSS depends on: each option carries the code the rules match against.
     */
    public function test_each_leave_type_option_carries_the_code_the_reveal_matches(): void
    {
        $html = $this->asEmployee()->get('/leave/apply')->assertOk()->getContent();

        foreach (LeaveType::active()->get() as $type) {
            $this->assertMatchesRegularExpression(
                '/<option value="'.$type->id.'"\s+data-code="'.preg_quote($type->code, '/').'"/',
                $html,
                "{$type->name} has no data-code, so 6.B cannot reveal its block"
            );
        }

        // A type the sheet prints no block for still resolves — to the catch-all,
        // never to nothing.
        $this->assertStringContainsString('lf-grp-other', $html);
    }

    /**
     * Section 7 is NOT on the entry form.
     *
     * It is completed by HR and signed by the applicant's department head. It
     * was drawn read-only here for fidelity with the paper sheet, which meant
     * an applicant scrolled past four boxes of somebody else's work to reach
     * the submit button. Fidelity belongs on the printed sheet, and that is
     * where it still lives -- see test_form_preview_draws_section_seven_in_full
     * below, and PrintedFormTest.
     */
    public function test_section_seven_is_not_on_the_entry_form(): void
    {
        $html = $this->asEmployee()->get('/leave/apply')->assertOk()->getContent();

        $this->assertStringNotContainsString('Action on application', $html);
        $this->assertStringNotContainsString('Certification of leave credits', $html);
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

    /**
     * The entry form and the preview are deliberately no longer the same
     * artefact: filling in is a modern form, filing produces the official
     * facsimile. What must hold is that the preview still draws the CSC sheet,
     * and that the entry form can supply everything the sheet displays.
     */
    public function test_the_preview_still_draws_the_official_sheet(): void
    {
        $request = $this->fileVacationLeave();

        $preview = $this->asEmployee()->get("/leave/{$request->id}/preview")->assertOk()->getContent();
        $form = $this->asEmployee()->get('/leave/apply')->assertOk()->getContent();

        // The preview keeps the printed sheet, verbatim.
        foreach (['APPLICATION FOR LEAVE', 'ANNEX A', 'Civil Service Form No. 6',
            '6.A TYPE OF LEAVE TO BE AVAILED OF', '6.B DETAILS OF LEAVE',
            '6.C NUMBER OF WORKING DAYS APPLIED FOR', '6.D COMMUTATION',
            '7. DETAILS OF ACTION ON APPLICATION', '(Signature of Applicant)'] as $marker) {
            $this->assertStringContainsString($marker, $preview, "preview is missing: {$marker}");
        }

        // ...and offers a way back to the list it was opened from, which is a
        // real link because this page is often opened in a new tab.
        $this->assertStringContainsString('class="back-link"', $preview);
        $this->assertStringContainsString('My Leave Requests', $preview);

        // The entry form is NOT the facsimile — that is the point of the change.
        $this->assertStringNotContainsString('csc-sheet', $form);

        // Every leave type the preview can tick, the entry form can choose.
        foreach (LeaveType::active()->pluck('name') as $name) {
            $this->assertStringContainsString((string) $name, $form,
                "the entry form cannot select: {$name}");
            $this->assertStringContainsString((string) $name, $preview,
                "the preview cannot show: {$name}");
        }
    }

    /**
     * A status looks the same wherever it appears. The dashboard used to build
     * its own chips inline while every other page rendered a Bootstrap badge,
     * so one request showed as a soft pill in one place and a solid block in
     * another. Both now come from leave/_status_badge.
     */
    public function test_a_status_renders_identically_on_every_page(): void
    {
        $request = $this->fileVacationLeave();

        $dashboard = $this->asEmployee()->get('/dashboard')->assertOk()->getContent();
        $list = $this->asEmployee()->get('/leave')->assertOk()->getContent();
        $preview = $this->asEmployee()->get("/leave/{$request->id}/preview")->assertOk()->getContent();

        // The shared chip: a .st pill with an icon, not a Bootstrap badge.
        foreach (['dashboard' => $dashboard, 'my leave requests' => $list, 'preview' => $preview] as $page => $html) {
            $this->assertStringContainsString('<span class="st st-wait"><i class="bi bi-clock"></i>Pending</span>',
                $html, "the {$page} page is not using the shared status chip");
            $this->assertStringNotContainsString('badge bg-info', $html,
                "the {$page} page still renders a Bootstrap status badge");
        }
    }

    /** Zoom controls are gone from the preview, as they are from the form. */
    public function test_the_form_preview_has_no_zoom_controls(): void
    {
        $request = $this->fileVacationLeave();
        $html = $this->asEmployee()->get("/leave/{$request->id}/preview")->assertOk()->getContent();

        $this->assertStringNotContainsString('data-csc-zoom', $html);
        $this->assertStringNotContainsString('data-zoom', $html);
    }

    /**
     * One click, one page: the form, the recorded details and the approval
     * progress all live on the preview now.
     */
    public function test_the_preview_carries_the_details_and_the_timeline(): void
    {
        $request = $this->fileVacationLeave();

        $this->asEmployee()->get("/leave/{$request->id}/preview")
            ->assertOk()
            ->assertSee('Approval progress')
            ->assertSee('Application Submitted')
            ->assertSee('Pending Approval')
            ->assertSee('Application details')
            ->assertSee('Supporting documents')
            ->assertSee($request->reference_no);
    }

    /** …so the list needs exactly one destination per row. */
    public function test_my_leave_requests_offers_one_destination_per_row(): void
    {
        $this->fileVacationLeave();
        $html = $this->asEmployee()->get('/leave')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'View Form'));
        $this->assertStringNotContainsString('>Details<', $html);
        $this->assertStringNotContainsString('Timeline', $html);
    }

    /**
     * The download is one legal-size page. dompdf writes the sheet size into
     * every page's MediaBox (612 x 1008 points = 8.5in x 14in), and each page
     * object is tagged /Type /Page — so both facts are checkable without a
     * PDF library.
     */
    public function test_the_downloaded_form_is_a_single_legal_page(): void
    {
        $request = $this->fileVacationLeave();

        $pdf = $this->asEmployee()->get("/leave/{$request->id}/form6")->assertOk()->getContent();

        $this->assertStringStartsWith('%PDF', $pdf);

        // "/Type /Pages" is the page TREE, not a page: exclude it.
        $pages = preg_match_all('#/Type\s*/Page(?![s])#', $pdf);
        $this->assertSame(1, $pages, "the PDF runs to {$pages} pages; it must fit one");

        $this->assertMatchesRegularExpression(
            '#/MediaBox\s*\[\s*0(\.0+)?\s+0(\.0+)?\s+612(\.0+)?\s+1008(\.0+)?\s*\]#',
            $pdf,
            'the PDF is not legal size (612 x 1008 points)'
        );
    }

    /** Files one approved-path vacation leave and returns it. */
    private function fileVacationLeave(): \App\Models\LeaveRequest
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

        return \App\Models\LeaveRequest::where('user_id', $this->employee->id)->firstOrFail();
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

    /**
     * 6.A is a dropdown on the entry form. It still posts an array, because a
     * single <select name="…[]"> yields one element — so the controller's
     * `size:1` rule is untouched and "exactly one type" is still a server
     * guarantee, not a property of the control.
     */
    public function test_leave_type_is_a_dropdown_that_still_posts_an_array(): void
    {
        $response = $this->asEmployee()->get('/leave/apply');

        $response->assertOk();
        $response->assertSee('name="leave_type_id[]"', false);
        // Not a checkbox list any more.
        $response->assertDontSee('type="checkbox" name="leave_type_id[]"', false);
        // Nothing is preselected on a new application: the placeholder is.
        $response->assertSee('<option value="">Select a leave type', false);

        // Scoped to the dropdown itself — "selected" appears elsewhere in the
        // page chrome, so scanning the whole document proves nothing.
        $html = $response->getContent();
        $start = strpos($html, '<select id="lf-type"');
        $this->assertNotFalse($start, 'the leave-type dropdown is missing');
        $select = substr($html, $start, strpos($html, '</select>', $start) - $start);
        $this->assertStringNotContainsString('selected', $select,
            'a leave type is preselected on a new application');
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

        // Assert against the SIDEBAR only. Change password stays in the top-bar
        // profile menu and Notifications has its own bell icon there, so testing
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
