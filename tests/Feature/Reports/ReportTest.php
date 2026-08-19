<?php

namespace Tests\Feature\Reports;

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
    private const LEAVE = ['employee-leave', 'department', 'monthly', 'annual', 'leave-balance'];

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

    /** Every security report downloads in all three formats. */
    public function test_the_security_reports_export_as_pdf_excel_and_csv(): void
    {
        $this->signIn('system-admin');

        foreach (self::SECURITY as $key) {
            $csv = $this->get('/reports/'.$key.'?format=csv')->assertOk();
            $this->assertStringContainsString('text/csv', $csv->headers->get('content-type'), $key);

            $xlsx = $this->get('/reports/'.$key.'?format=xlsx')->assertOk();
            $this->assertStringContainsString('spreadsheet', $xlsx->headers->get('content-type'), $key);

            $pdf = $this->get('/reports/'.$key.'?format=pdf')->assertOk();
            $this->assertStringContainsString('application/pdf', $pdf->headers->get('content-type'), $key);
        }
    }
}
