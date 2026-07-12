<?php

namespace Tests\Unit\Leave;

use App\Models\Holiday;
use App\Services\Leave\WorkingDayCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WorkingDayCalculatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_excludes_weekends(): void
    {
        // Mon 2026-07-13 to Fri 2026-07-17 = 5 working days
        $days = (new WorkingDayCalculator)->count(Carbon::parse('2026-07-13'), Carbon::parse('2026-07-17'));
        $this->assertSame(5.0, $days);
    }

    public function test_a_full_week_range_counts_only_weekdays(): void
    {
        // Mon 2026-07-13 to Sun 2026-07-19 = 5 working days (Sat/Sun excluded)
        $days = (new WorkingDayCalculator)->count(Carbon::parse('2026-07-13'), Carbon::parse('2026-07-19'));
        $this->assertSame(5.0, $days);
    }

    public function test_it_excludes_holidays(): void
    {
        Holiday::create(['date' => '2026-07-15', 'name' => 'Test Holiday', 'scope' => 'local']);
        $days = (new WorkingDayCalculator)->count(Carbon::parse('2026-07-13'), Carbon::parse('2026-07-17'));
        $this->assertSame(4.0, $days);
    }
}
