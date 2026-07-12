<?php

namespace App\Services\Leave;

use App\Models\Holiday;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;

/** Counts working days in an inclusive range, excluding weekends and holidays. */
class WorkingDayCalculator
{
    /** @var array<string,bool> cached holiday lookup */
    private array $holidayCache = [];

    public function count(Carbon $start, Carbon $end): float
    {
        if ($end->lt($start)) {
            return 0;
        }

        $holidays = $this->holidaysBetween($start, $end);
        $days = 0;
        foreach (CarbonPeriod::create($start->copy()->startOfDay(), $end->copy()->startOfDay()) as $day) {
            if ($day->isWeekend()) {
                continue;
            }
            if (isset($holidays[$day->toDateString()])) {
                continue;
            }
            $days++;
        }

        return (float) $days;
    }

    /** @return array<string,bool> */
    private function holidaysBetween(Carbon $start, Carbon $end): array
    {
        $key = $start->toDateString().'|'.$end->toDateString();
        if (isset($this->holidayCache[$key])) {
            return $this->holidayCache[$key];
        }

        $dates = Holiday::whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->pluck('date')
            ->mapWithKeys(fn ($d) => [Carbon::parse($d)->toDateString() => true])
            ->all();

        return $this->holidayCache[$key] = $dates;
    }
}
