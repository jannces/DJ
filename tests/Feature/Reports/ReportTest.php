<?php

namespace Tests\Feature\Reports;

use App\Exports\GenericReportExport;
use App\Models\Department;
use App\Models\EmployeeProfile;
use App\Models\LeaveType;
use App\Services\Reports\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string> */
    private const SECURITY = ['intrusion', 'audit', 'blocked-login', 'user-activity'];

    /** @var array<string> */
    private const LEAVE = [
        'employee-leave', 'leave-type-summary', 'department',
        'pending', 'mandatory-leave', 'leave-balance',
    ];

    /** @var array<string> the two formats a report downloads as */
    private const FORMATS = ['pdf', 'xlsx'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
    }

    private function signIn(string $role): \App\Models\User
    {
        $user = $this->makeUser($role);
        $this->actingAs($user);
        session(['otp_verified' => true]);

        return $user;
    }

    public function test_every_report_builds_with_the_uniform_structure(): void
    {
        $service = app(ReportService::class);
        foreach (array_keys(ReportService::CATALOGUE) as $key) {
            $data = $service->build($key);
            $this->assertArrayHasKey('columns', $data, $key);
            $this->assertArrayHasKey('rows', $data, $key);
            $this->assertNotEmpty($data['columns'], $key);
            $this->assertSame($key, $data['key']);
        }
    }

    /** The catalogue and the two lists this test asserts against must not drift. */
    public function test_the_catalogue_is_split_into_exactly_those_two_groups(): void
    {
        $grouped = [];
        foreach (ReportService::CATALOGUE as $key => $report) {
            $grouped[$report['group']][] = $key;
        }

        $this->assertEqualsCanonicalizing(self::SECURITY, $grouped['security']);
        $this->assertEqualsCanonicalizing(self::LEAVE, $grouped['leave']);
    }

    /**
     * The System Administrator runs the installation; they do not decide leave
     * and hold no permission over it. Before this, every report was gated on
     * `reports.generate` alone — which they do hold — so the reports module was
     * a way to read and export every employee's leave record without ever
     * holding a leave permission.
     */
    public function test_the_administrator_gets_the_security_reports_only(): void
    {
        $this->signIn('system-admin');

        $html = $this->get('/reports')->assertOk()->getContent();

        foreach (self::SECURITY as $key) {
            $this->assertStringContainsString(route('reports.generate', $key), $html, $key);
        }
        foreach (self::LEAVE as $key) {
            $this->assertStringNotContainsString(route('reports.generate', $key), $html, $key);
        }
    }

    /** Hiding a card is not access control; the route has to refuse as well. */
    public function test_the_administrator_is_refused_every_leave_report_at_the_url(): void
    {
        $this->signIn('system-admin');

        foreach (self::LEAVE as $key) {
            $this->get('/reports/'.$key)->assertForbidden();
            // The exports are the same route with a query string, so a denial
            // that only covered the HTML view would leak the whole dataset.
            // `csv` is in the list although the format was dropped: an
            // unrecognised format falls through to the on-screen view, and that
            // view must be refused too.
            foreach (['csv', 'xlsx', 'pdf'] as $format) {
                $this->get('/reports/'.$key.'?format='.$format)->assertForbidden();
            }
        }
    }

    public function test_hr_gets_the_leave_reports_only(): void
    {
        $dept = Department::factory()->create();
        LeaveType::firstOrCreate(['code' => 'VL'], ['name' => 'Vacation', 'active' => true]);
        $hr = $this->signIn('hr');
        EmployeeProfile::factory()->create(['user_id' => $hr->id, 'department_id' => $dept->id]);

        $html = $this->get('/reports')->assertOk()->getContent();
        foreach (self::LEAVE as $key) {
            $this->assertStringContainsString(route('reports.generate', $key), $html, $key);
        }
        foreach (self::SECURITY as $key) {
            $this->assertStringNotContainsString(route('reports.generate', $key), $html, $key);
        }

        $this->get('/reports/employee-leave')->assertOk();
        foreach (self::SECURITY as $key) {
            $this->get('/reports/'.$key)->assertForbidden();
        }
    }

    public function test_an_employee_reaches_no_report_at_all(): void
    {
        $this->signIn('employee');

        // reports.generate is not theirs, so the route group turns them away
        // before any of the per-report checks are reached.
        $this->get('/reports')->assertForbidden();
        $this->get('/reports/employee-leave')->assertForbidden();
        $this->get('/reports/intrusion')->assertForbidden();
    }

    public function test_an_unknown_report_is_a_404_not_a_403(): void
    {
        $this->signIn('system-admin');
        $this->get('/reports/does-not-exist')->assertNotFound();
    }

    /**
     * Every report covers one month or one year — never a free date range. Two
     * people asking the same question then get the same period, and the file
     * carries a caption a reader can check it against.
     */
    public function test_a_report_covers_a_month_or_a_year_and_says_which(): void
    {
        $service = app(ReportService::class);

        $month = $service->build('intrusion', ['period' => 'monthly', 'year' => 2026, 'month' => 3]);
        $this->assertSame('March 2026', $month['period']);

        $year = $service->build('intrusion', ['period' => 'annual', 'year' => 2026]);
        $this->assertSame('Year 2026', $year['period']);

        // No period at all still resolves to something nameable.
        $this->assertSame(now()->format('F Y'), $service->build('intrusion', [])['period']);
    }

    /** The year arrives from a query string, so it cannot be trusted raw. */
    public function test_a_nonsense_period_is_clamped_rather_than_obeyed(): void
    {
        $service = app(ReportService::class);

        $this->assertSame('Year '.now()->year, $service->build('audit', ['period' => 'annual', 'year' => 0])['period']);
        $this->assertSame('Year 2000', $service->build('audit', ['period' => 'annual', 'year' => 1066])['period']);
        $this->assertSame('December 2026', $service->build('audit', ['year' => 2026, 'month' => 99])['period']);
    }

    /**
     * The download says which period it is — in the filename and on the first
     * line of the sheet. A file that does not carry its own period is
     * indistinguishable from any other month's once it is in a folder.
     */
    public function test_the_period_reaches_the_downloaded_file(): void
    {
        $data = app(ReportService::class)
            ->build('audit', ['period' => 'monthly', 'year' => 2026, 'month' => 3]);

        $headings = (new GenericReportExport($data))->headings();
        $this->assertSame('Audit Report — March 2026', $headings[0][0]);
        $this->assertSame($data['columns'], $headings[1], 'the column names keep a row of their own');

        // Every row the same width, or the sheet comes out ragged.
        $this->assertCount(count($data['columns']), $headings[0],
            'the title row is padded to the column count');

        $this->signIn('system-admin');

        $this->assertStringContainsString('audit-march-2026',
            $this->get('/reports/audit?format=xlsx&period=monthly&year=2026&month=3')
                ->assertOk()->headers->get('content-disposition'));

        $this->assertStringContainsString('audit-year-2026',
            $this->get('/reports/audit?format=xlsx&period=annual&year=2026')
                ->assertOk()->headers->get('content-disposition'));
    }

    /**
     * CSV is gone from the backend, not only from the card.
     *
     * An export route nothing links to is exactly the kind of thing that
     * outlives the decision to drop it — and the standing rule on this system
     * is that an export an account may not have is refused by the server, not
     * hidden in the markup. `?format=csv` falls through to the on-screen view
     * like any other unrecognised format, which is still permission-checked.
     */
    public function test_csv_no_longer_downloads(): void
    {
        $this->signIn('system-admin');

        $response = $this->get('/reports/audit?format=csv')->assertOk();

        $this->assertNull($response->headers->get('content-disposition'),
            'CSV is still downloading; the format was supposed to be gone');
        $this->assertStringContainsString('Audit Report', $response->getContent());
    }

    /** Every security report downloads in both formats. */
    public function test_the_security_reports_export_as_pdf_and_excel(): void
    {
        $this->signIn('system-admin');

        // Asserted on what the browser saves, not on the MIME type: that is
        // guessed from a temp file by the export library and is its business,
        // while the filename is ours and is what lands in the user's folder.
        foreach (self::SECURITY as $key) {
            foreach (self::FORMATS as $format) {
                $disposition = $this->get('/reports/'.$key.'?format='.$format)
                    ->assertOk()->headers->get('content-disposition');

                $this->assertStringContainsString('attachment', $disposition, "{$key} {$format}");
                $this->assertStringContainsString('.'.$format, $disposition, "{$key} {$format}");
            }
        }
    }
}
