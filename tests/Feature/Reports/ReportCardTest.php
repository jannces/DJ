<?php

namespace Tests\Feature\Reports;

use App\Models\Department;
use App\Models\EmployeeProfile;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Reports\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The report card: one period control, and only where there is a period.
 *
 * There used to be three controls naming one thing — a Monthly/Yearly dropdown,
 * a month field and a year field — where the first silently decided whether the
 * second meant anything. Worse, two reports carried the whole apparatus and read
 * none of it, so a file captioned "August 2026" could contain every row ever
 * recorded.
 */
class ReportCardTest extends TestCase
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

    private function card(string $html, string $key): string
    {
        preg_match('#<form[^>]*'.preg_quote(route('reports.generate', $key), '#').'.*?</form>#s', $html, $m);
        $this->assertNotEmpty($m, "there is no card for {$key}");

        return $m[0];
    }

    // ------------------------------------------------------------ the control

    /**
     * The segment IS the form field. `period` is the radio's own name, so the
     * thing you press is the value that gets submitted — there is nothing left
     * to keep in step with it.
     */
    public function test_the_period_is_one_control_and_it_is_the_field(): void
    {
        $html = $this->signIn('system-admin') ? $this->get('/reports')->assertOk()->getContent() : '';
        $card = $this->card($html, 'intrusion');

        $this->assertStringContainsString('<input type="radio" name="period" value="monthly"', $card);
        $this->assertStringContainsString('<input type="radio" name="period" value="annual"', $card);

        // The dropdown that named the period a second time is gone.
        $this->assertStringNotContainsString('<select name="period"', $card);

        // Exactly one month field and one year field, and the year is a list
        // rather than a spinner.
        $this->assertSame(1, substr_count($card, 'name="month"'));
        $this->assertSame(1, substr_count($card, 'name="year"'));
        $this->assertStringNotContainsString('type="number"', $card);
    }

    public function test_choosing_year_removes_the_month_rather_than_ignoring_it(): void
    {
        $css = preg_replace('/\s+/', '', file_get_contents(public_path('css/app.css')));

        $this->assertStringContainsString('.report-card:has(.per-annual:checked).period-month{display:none', $css,
            'the month field survives a Year selection, so the card offers a control that does nothing');
    }

    /** A radio group and :has(). Nothing on this page needs a script. */
    public function test_the_control_needs_no_script(): void
    {
        $this->signIn('system-admin');
        $card = $this->card($this->get('/reports')->assertOk()->getContent(), 'audit');

        $this->assertStringNotContainsString('<script', $card);
        $this->assertStringNotContainsString('onchange', $card);
    }

    // ---------------------------------------------------- where there is none

    /**
     * A balance and a queue are true as of now. Offering a month picker that
     * changes no figure is worse than offering none — the caption would claim a
     * period the rows do not have.
     */
    public function test_a_snapshot_report_offers_no_period_at_all(): void
    {
        $this->signIn('hr');
        $html = $this->get('/reports')->assertOk()->getContent();

        foreach (['leave-balance', 'pending'] as $key) {
            $card = $this->card($html, $key);

            $this->assertStringNotContainsString('name="period"', $card, $key);
            $this->assertStringNotContainsString('name="month"', $card, $key);
            $this->assertStringNotContainsString('name="year"', $card, $key);
            $this->assertStringContainsString('As of', $card, $key.' does not say what it is true as of');
        }
    }

    public function test_a_snapshot_says_so_in_its_caption_and_filename(): void
    {
        $data = app(ReportService::class)->build('leave-balance', ['period' => 'monthly', 'year' => 2020, 'month' => 3]);

        $this->assertStringContainsString('As of', $data['period'],
            'a balance captioned "March 2020" is a claim about 2020 the rows cannot support');
        $this->assertStringNotContainsString('2020', $data['period']);
    }

    /**
     * Mandatory Leave is a calendar-year obligation. "March's mandatory leave"
     * is not a thing, so the card offers a year and no month.
     */
    public function test_a_year_scoped_report_offers_a_year_and_not_a_month(): void
    {
        $this->signIn('hr');
        $card = $this->card($this->get('/reports')->assertOk()->getContent(), 'mandatory-leave');

        $this->assertStringContainsString('name="year"', $card);
        $this->assertStringNotContainsString('name="month"', $card);
        $this->assertStringNotContainsString('name="period"', $card);

        $this->assertSame('Year 2024',
            app(ReportService::class)->build('mandatory-leave', ['year' => 2024])['period']);
    }

    // ------------------------------------------------ the two that lied

    /**
     * Department Report counted every request ever filed, whatever period was
     * chosen, and printed the chosen period at the top of the file.
     */
    public function test_the_department_report_now_counts_the_period_it_names(): void
    {
        $office = Department::create(['name' => 'Municipal Treasury Office', 'code' => 'MTO']);
        $employee = $this->makeUser('employee');
        EmployeeProfile::factory()->create(['user_id' => $employee->id, 'department_id' => $office->id]);
        $vl = LeaveType::where('code', 'VL')->firstOrFail();

        foreach ([now()->startOfMonth(), now()->copy()->subYear()] as $when) {
            LeaveRequest::factory()->create([
                'user_id' => $employee->id, 'leave_type_id' => $vl->id, 'status' => 'approved',
                'date_filed' => $when->toDateString(),
                'start_date' => $when->toDateString(), 'end_date' => $when->toDateString(),
            ]);
        }

        $service = app(ReportService::class);

        $thisMonth = $service->build('department', [
            'period' => 'monthly', 'year' => now()->year, 'month' => now()->month,
        ]);
        $lastYear = $service->build('department', ['period' => 'annual', 'year' => now()->year - 1]);

        // Column 3 is "Filed".
        $this->assertSame(1, $thisMonth['rows'][0][3], 'this month should hold one of the two');
        $this->assertSame(1, $lastYear['rows'][0][3], 'last year should hold the other');
    }

    // ----------------------------------------------------- the new reports

    public function test_pending_lists_what_is_waiting_oldest_first_with_its_age(): void
    {
        $employee = $this->makeUser('employee');
        $vl = LeaveType::where('code', 'VL')->firstOrFail();

        foreach ([['pending', 9], ['hr_review', 2], ['approved', 30]] as [$status, $age]) {
            LeaveRequest::factory()->create([
                'user_id' => $employee->id, 'leave_type_id' => $vl->id, 'status' => $status,
                'date_filed' => now()->subDays($age)->toDateString(),
                'start_date' => now()->addDay()->toDateString(),
                'end_date' => now()->addDay()->toDateString(),
            ]);
        }

        $data = app(ReportService::class)->build('pending');

        $this->assertCount(2, $data['rows'], 'a decided application is not waiting on anybody');
        // Column 5 is "Days Waiting".
        $this->assertSame(9, $data['rows'][0][5], 'the longest wait sorts first');
        $this->assertSame(2, $data['rows'][1][5]);
    }

    public function test_mandatory_leave_lists_only_those_who_have_filed_none(): void
    {
        $fl = LeaveType::where('code', 'FL')->firstOrFail();

        $cases = [
            'Filed' => [5, 5, 0],
            'NotFiled' => [5, 0, 5],
            // Credits never accrued: not out of compliance, so not on the list.
            'NoCredits' => [0, 0, 0],
        ];

        foreach ($cases as $name => [$earned, $used, $balance]) {
            $user = User::factory()->create(['name' => $name]);
            LeaveBalance::create([
                'user_id' => $user->id, 'leave_type_id' => $fl->id,
                'earned' => $earned, 'used' => $used, 'balance' => $balance,
            ]);
        }

        $data = app(ReportService::class)->build('mandatory-leave');
        $names = array_column($data['rows'], 0);

        $this->assertSame(['NotFiled'], $names);
    }

    // ------------------------------------------------------------- the buttons

    /**
     * View is what somebody presses almost every time, so it carries the accent
     * and the wider column; the two formats sit beside it in the same row.
     */
    public function test_each_card_offers_view_pdf_and_excel_and_nothing_else(): void
    {
        $this->signIn('system-admin');
        $card = $this->card($this->get('/reports')->assertOk()->getContent(), 'intrusion');

        $this->assertStringContainsString('class="btn-view"', $card);
        $this->assertStringContainsString('name="format" value="pdf"', $card);
        $this->assertStringContainsString('name="format" value="xlsx"', $card);

        $this->assertStringNotContainsString('value="csv"', $card, 'CSV was dropped');
        $this->assertStringNotContainsString('>CSV<', $card);
    }

    /**
     * The format colours are deliberately not the KPI red and green: on the
     * dashboards those mean "a problem" and "healthy", and a red PDF button
     * must not read as a warning.
     */
    public function test_the_format_colours_are_their_own_and_follow_the_apps_toggle(): void
    {
        $css = preg_replace('#/\*.*?\*/#s', '', file_get_contents(public_path('css/app.css')));

        foreach (['--fmt-pdf', '--fmt-xls'] as $token) {
            $this->assertSame(2, substr_count($css, $token.':'),
                $token.' needs a light step and a dark one, and no more');
        }

        $this->assertMatchesRegularExpression('/\[data-bs-theme="dark"\][^{]*\{[^}]*--fmt-pdf:/s', $css,
            'the format colours have no dark step under the attribute the app actually sets');

        // Not the status pair.
        preg_match('/--fmt-pdf:(#[0-9A-Fa-f]{6})/', $css, $pdf);
        preg_match('/--k-bad:(#[0-9A-Fa-f]{6})/', $css, $bad);
        $this->assertNotSame(strtolower($bad[1]), strtolower($pdf[1]),
            'PDF is using the colour that means "a problem" everywhere else');
    }

    /** On the result page there is no View — you are already looking at it. */
    public function test_the_result_page_offers_the_same_two_formats(): void
    {
        $this->signIn('system-admin');
        $html = $this->get('/reports/audit')->assertOk()->getContent();

        $this->assertStringContainsString('fmt-pdf', $html);
        $this->assertStringContainsString('fmt-xls', $html);
        $this->assertStringNotContainsString('format=csv', $html);
        $this->assertStringNotContainsString('class="btn-view"', $html);
    }

    // --------------------------------------------------------------- the page

    public function test_every_card_says_what_its_report_contains(): void
    {
        $this->signIn('hr');
        $html = $this->get('/reports')->assertOk()->getContent();

        foreach (ReportService::CATALOGUE as $key => $report) {
            if ($report['group'] !== 'leave') {
                continue;
            }
            $this->assertStringContainsString(e($report['about']), $html,
                $key.' has a title and nothing else, which is a menu you must have memorised');
        }
    }

    /** The dropped report is gone from the catalogue, the page and the URL. */
    public function test_the_monthly_report_is_gone(): void
    {
        $this->assertArrayNotHasKey('monthly', ReportService::CATALOGUE);
        $this->assertArrayNotHasKey('annual', ReportService::CATALOGUE);

        $this->signIn('hr');
        $this->get('/reports/monthly')->assertNotFound();
        $this->get('/reports/annual')->assertNotFound();
    }
}
