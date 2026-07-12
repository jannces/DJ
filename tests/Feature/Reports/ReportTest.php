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

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
    }

    public function test_every_report_builds_with_the_uniform_structure(): void
    {
        $service = app(ReportService::class);
        foreach (array_keys(ReportService::REPORTS) as $key) {
            $data = $service->build($key);
            $this->assertArrayHasKey('columns', $data, $key);
            $this->assertArrayHasKey('rows', $data, $key);
            $this->assertNotEmpty($data['columns'], $key);
            $this->assertSame($key, $data['key']);
        }
    }

    public function test_hr_can_open_operational_reports_but_not_security_reports(): void
    {
        $dept = Department::factory()->create();
        LeaveType::firstOrCreate(['code' => 'VL'], ['name' => 'Vacation', 'active' => true]);
        $hr = $this->makeUser('hr');
        EmployeeProfile::factory()->create(['user_id' => $hr->id, 'department_id' => $dept->id]);
        $this->actingAs($hr);
        session(['otp_verified' => true]);

        $this->get('/reports/employee-leave')->assertOk();
        $this->get('/reports/intrusion')->assertForbidden(); // needs reports.security
    }

    public function test_admin_can_export_a_report_as_csv(): void
    {
        $admin = $this->makeUser('system-admin');
        // grant reports.generate via role system-admin already has it
        $this->actingAs($admin);
        session(['otp_verified' => true]);

        $response = $this->get('/reports/audit?format=csv');
        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('content-type'));
    }
}
