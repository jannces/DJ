<?php

namespace Tests\Feature\Reports;

use App\Models\LeaveType;
use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A printed report should look like it came from the same office as the
 * CSC Form 6 it sits beside in the folder.
 */
class ReportLetterheadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
    }

    private function render(): string
    {
        return view('reports.pdf', ['data' => [
            'title' => 'Employee Leave Report',
            'period' => 'January – December 2026',
            'generated_at' => now()->toDateTimeString(),
            'columns' => ['Employee', 'Days'],
            'rows' => [['Juan Dela Cruz', '5']],
            'filters' => [],
        ]])->render();
    }

    public function test_the_report_carries_the_full_letterhead(): void
    {
        SystemSetting::set('general.lgu_name', 'MUNICIPALITY OF ALICIA');
        SystemSetting::set('general.lgu_address', 'Magsaysay, Alicia');

        $html = $this->render();

        $this->assertStringContainsString('Republic of the Philippines', $html);
        $this->assertStringContainsString('Province of Isabela', $html);
        $this->assertStringContainsString('MUNICIPALITY OF ALICIA', $html);
        $this->assertStringContainsString('Magsaysay, Alicia', $html);
    }

    public function test_the_letterhead_follows_the_settings(): void
    {
        // Both keys must be rows the settings screen can actually change:
        // SystemSetting::set() updates, and updates nothing for a key that was
        // never inserted, which is how the address came to be unchangeable.
        SystemSetting::set('general.lgu_name', 'MUNICIPALITY OF SAN MATEO');
        SystemSetting::set('general.lgu_address', 'Poblacion, San Mateo');

        $html = $this->render();

        $this->assertStringContainsString('MUNICIPALITY OF SAN MATEO', $html);
        $this->assertStringContainsString('Poblacion, San Mateo', $html);
    }

    public function test_the_address_is_a_setting_the_office_can_change(): void
    {
        // Regression: general.lgu_address was read by the CSC Form 6 but never
        // seeded, so every printed form carried the fallback address and no
        // amount of editing in System Settings could change it.
        $this->assertDatabaseHas('system_settings', ['key' => 'general.lgu_address']);

        SystemSetting::set('general.lgu_address', 'Bagumbayan, Alicia');

        $this->assertSame('Bagumbayan, Alicia', SystemSetting::get('general.lgu_address'));
        $blank = view('leave.form6', [
            'r' => null, 'vl' => 0.0, 'sl' => 0.0,
            'types' => LeaveType::orderBy('id')->get(), 'paper' => 'a4',
        ])->render();

        $this->assertStringContainsString('Bagumbayan, Alicia', $blank);
    }

    public function test_the_title_and_the_data_are_still_there(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('Employee Leave Report', $html);
        $this->assertStringContainsString('January – December 2026', $html);
        $this->assertStringContainsString('Juan Dela Cruz', $html);
    }
}
