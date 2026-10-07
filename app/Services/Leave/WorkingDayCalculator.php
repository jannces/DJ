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

    /**
     * The days a request consumes, in whichever unit this type is granted in.
     *
     * Not every entitlement is in working days. Maternity leave is 105 days
     * under RA 11210 and those are calendar days; counting them as working
     * days stretches 105 into about 147 calendar days, which is forty per cent
     * more leave than the law provides. The same holds for every entitlement
     * written as a span of months -- rehabilitation, study, the special leave
     * for women, adoption.
     *
     * Vacation, Sick, Forced and Special Privilege Leave stay in working days,
     * which is what the Omnibus Rules say for those.
     */
    public function countFor(\App\Models\LeaveType $type, Carbon $start, Carbon $end): float
    {
        return $type->counts_calendar_days
            ? $this->countCalendarDays($start, $end)
            : $this->count($start, $end);
    }

    /** Every day in the range, weekends and holidays included. */
    public function countCalendarDays(Carbon $start, Carbon $end): float
    {
        if ($end->lt($start)) {
            return 0;
        }

        return (float) ($start->copy()->startOfDay()->diffInDays($end->copy()->startOfDay()) + 1);
    }

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
