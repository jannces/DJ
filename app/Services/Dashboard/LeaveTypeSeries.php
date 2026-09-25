<?php

namespace App\Services\Dashboard;

use App\Models\Department;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Leave type as a series, across the three panels that read it.
 *
 * The dashboard shows leave type three ways -- its share of the year, its split
 * across the offices, its shape month by month -- and they are only readable
 * together if Sick Leave is the same colour in all three. So the decision of
 * which types get a colour is made once, here, and the panels take it.
 *
 * TWO RULES DECIDE THE COLOURS, and both exist to stop the chart repainting
 * itself under the reader:
 *
 *   · The set is chosen from the YEAR, never from the window being viewed.
 *     Switching the type panel from "this year" to "this month" must not hand
 *     Sick Leave a different colour because it slipped a place in a smaller
 *     sample.
 *
 *   · Inside that set the slots are handed out in leave-type order, not by
 *     rank. Rank is already carried by size and position; spending colour on
 *     it too would mean a quiet month reshuffles every hue on the page.
 *
 * The CSC has sixteen leave types and a categorical palette has five usable
 * hues, so everything outside the set folds into "Other" in grey rather than
 * being given an invented sixth colour. In practice the tail is Terminal,
 * Adoption, Rehabilitation -- types an LGU files once in a decade.
 */
final class LeaveTypeSeries
{
    /** How many types get a hue of their own. The sixth slot is "Other". */
    public const SLOTS = 5;

    public const OTHER = 'other';

    /** Cached per year: the same page asks three times. */
    private array $palettes = [];

    /**
     * Which leave types hold a colour this year, in slot order.
     *
     * @return Collection<int, array{key: string, name: string, code: string}>
     *                                                                         keyed by leave_type id, plus the OTHER entry last
     */
    public function palette(int $year): Collection
    {
        if (isset($this->palettes[$year])) {
            return $this->palettes[$year];
        }

        $from = Carbon::create($year)->startOfYear();
        $to = Carbon::create($year)->endOfYear();

        $counts = LeaveRequest::query()
            ->whereBetween('date_filed', [$from, $to])
            ->selectRaw('leave_type_id, count(*) as total')
            ->groupBy('leave_type_id')
            ->pluck('total', 'leave_type_id');

        // The five most filed this year. Ties break on type id so the answer is
        // the same on every request rather than however the database felt.
        $chosen = $counts->sortByDesc(fn ($total, $id) => [$total, -$id])
            ->take(self::SLOTS)
            ->keys()
            ->map(fn ($id) => (int) $id);

        // An office with no filings at all still needs something to draw, or
        // the first month of use shows six grey slices called "Other".
        if ($chosen->isEmpty()) {
            $chosen = LeaveType::where('active', true)->orderBy('id')
                ->limit(self::SLOTS)->pluck('id')->map(fn ($id) => (int) $id);
        }

        $types = LeaveType::whereIn('id', $chosen)->orderBy('id')
            ->get(['id', 'code', 'name']);

        $palette = collect();
        foreach ($types->values() as $slot => $type) {
            $palette[$type->id] = [
                'key' => 's'.($slot + 1),
                'name' => $type->name,
                'code' => $type->code,
            ];
        }

        $palette[0] = ['key' => self::OTHER, 'name' => 'Other leave types', 'code' => '—'];

        return $this->palettes[$year] = $palette;
    }

    /** The slot a leave type falls in, or "Other". */
    private function slotFor(Collection $palette, ?int $typeId): array
    {
        return $palette[$typeId] ?? $palette[0];
    }

    // ===================================================================
    //  Share of the year — the ring
    // ===================================================================

    /**
     * Applications by leave type over a window, as slices of one whole.
     *
     * A ring rather than bars because the question here is share of a total,
     * and the total is the number in the middle. Ranked bars answer "which is
     * the most"; they cannot show that Vacation and Sick together are two
     * thirds of everything the office files, which is the thing HR plans
     * around.
     *
     * @return array{total: int, slices: list<array{key: string, name: string,
     *               value: int, share: float, offset: float}>}
     */
    public function distribution(CarbonInterface $from, CarbonInterface $to, ?int $year = null): array
    {
        $palette = $this->palette($year ?? (int) $to->year);

        $counts = LeaveRequest::query()
            ->whereBetween('date_filed', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->selectRaw('leave_type_id, count(*) as total')
            ->groupBy('leave_type_id')
            ->pluck('total', 'leave_type_id');

        $totals = [];
        foreach ($counts as $typeId => $total) {
            $slot = $this->slotFor($palette, (int) $typeId);
            $totals[$slot['key']] = ($totals[$slot['key']] ?? 0) + (int) $total;
        }

        $overall = array_sum($totals);
        $slices = [];
        $offset = 0.0;

        foreach ($palette as $entry) {
            $value = $totals[$entry['key']] ?? 0;
            if ($value === 0) {
                continue;
            }

            $share = $overall > 0 ? $value / $overall * 100 : 0;
            $slices[] = [
                'key' => $entry['key'],
                'name' => $entry['name'],
                'value' => $value,
                'share' => round($share, 1),
                // Where this slice starts, as a percentage of the ring. The
                // partial turns it into a dash offset.
                'offset' => round($offset, 3),
            ];
            $offset += $share;
        }

        return ['total' => $overall, 'slices' => $slices];
    }

    // ===================================================================
    //  Split across the offices — the stack
    // ===================================================================

    /**
     * Every office, and what its applications were made of.
     *
     * The ranked bar it replaces said the Treasurer's Office filed the most.
     * That is a fact about headcount as much as anything. What HR can act on is
     * the composition: an office whose filings are three-quarters Sick Leave is
     * a different problem from one that is three-quarters Vacation, and the two
     * were the same length of bar.
     *
     * @return array{max: int, rows: list<array{name: string, total: int,
     *               segments: list<array{key: string, name: string, value: int, pct: float}>}>}
     */
    public function byOffice(CarbonInterface $from, CarbonInterface $to, ?int $year = null): array
    {
        $palette = $this->palette($year ?? (int) $to->year);

        $filings = LeaveRequest::query()
            ->leftJoin('employee_profiles', 'employee_profiles.user_id', '=', 'leave_requests.user_id')
            ->whereBetween('leave_requests.date_filed', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->selectRaw('employee_profiles.department_id as department_id, leave_requests.leave_type_id, count(*) as total')
            ->groupBy('employee_profiles.department_id', 'leave_requests.leave_type_id')
            ->get();

        // department id => slot key => count
        $grid = [];
        foreach ($filings as $row) {
            $office = $row->department_id === null ? 0 : (int) $row->department_id;
            $slot = $this->slotFor($palette, (int) $row->leave_type_id);
            $grid[$office][$slot['key']] = ($grid[$office][$slot['key']] ?? 0) + (int) $row->total;
        }

        $offices = Department::orderBy('id')->get(['id', 'name'])
            ->map(fn ($d) => ['id' => (int) $d->id, 'name' => $d->name]);

        // An application filed by somebody with no employee record still
        // happened; dropping it would make the offices add up to less than the
        // total on the ring beside them.
        if (isset($grid[0])) {
            $offices->push(['id' => 0, 'name' => 'No office on record']);
        }

        $rows = [];
        $max = 0;

        foreach ($offices as $office) {
            $counts = $grid[$office['id']] ?? [];
            $total = array_sum($counts);
            $max = max($max, $total);

            $segments = [];
            foreach ($palette as $entry) {
                $value = $counts[$entry['key']] ?? 0;
                if ($value === 0) {
                    continue;
                }
                $segments[] = [
                    'key' => $entry['key'],
                    'name' => $entry['name'],
                    'value' => $value,
                    'pct' => $total > 0 ? round($value / $total * 100, 3) : 0,
                ];
            }

            $rows[] = ['name' => $office['name'], 'total' => $total, 'segments' => $segments];
        }

        usort($rows, fn ($a, $b) => [$b['total'], $a['name']] <=> [$a['total'], $b['name']]);

        // An office that filed nothing is still an answer, and a missing row
        // would read as "no data". But the LGU has sixteen offices and most of
        // them file nothing in a quiet month, so twelve empty tracks is a
        // panel of nothing. They are named underneath instead: the same
        // statement, one line rather than twelve.
        $silent = array_values(array_map(
            fn ($row) => $row['name'],
            array_filter($rows, fn ($row) => $row['total'] === 0),
        ));

        return [
            'max' => $max,
            'rows' => array_values(array_filter($rows, fn ($row) => $row['total'] > 0)),
            'silent' => $silent,
        ];
    }

    // ===================================================================
    //  Shape over time — the lines
    // ===================================================================

    /**
     * Twelve months, one line per leave type.
     *
     * The single line it replaces showed filings rising in June without saying
     * what kind. Vacation rising into the school holidays is a plan; Sick
     * rising is an outbreak. They are the same line.
     *
     * @return array{labels: list<string>, top: int, series: list<array>, total: int}
     */
    public function byMonth(int $year): array
    {
        $labels = [];
        for ($m = 1; $m <= 12; $m++) {
            $labels[] = Carbon::create($year, $m)->format('M');
        }

        $rows = LeaveRequest::query()
            ->whereBetween('date_filed', [
                Carbon::create($year)->startOfYear(), Carbon::create($year)->endOfYear(),
            ])
            ->get(['leave_type_id', 'date_filed']);

        $previous = LeaveRequest::query()
            ->whereBetween('date_filed', [
                Carbon::create($year - 1)->startOfYear(), Carbon::create($year - 1)->endOfYear(),
            ])
            ->selectRaw('leave_type_id, count(*) as total')
            ->groupBy('leave_type_id')
            ->pluck('total', 'leave_type_id');

        // Nothing to compare against in the first year of use, and repeating
        // "none last year" down every row of the breakdown says only that the
        // system is new.
        return $this->build(
            $this->palette($year), $labels,
            $rows->groupBy(fn ($r) => (int) $r->date_filed->month - 1),
            $previous, $previous->sum() > 0 ? 'on '.($year - 1) : '',
        );
    }

    /**
     * The same lines over several years.
     *
     * Thin in the first year of use, and that is honest -- a year of operation
     * is one point. It is the panel that answers "is Sick Leave climbing", and
     * that question cannot be asked of twelve months.
     */
    public function byYear(int $years = 5): array
    {
        $latest = (int) now()->year;

        // Start where the record starts, not five years back from today. The
        // system went in this year, so counting back to 2022 draws four empty
        // columns and a flat line along the floor -- a picture of the axis
        // rather than of the leave, and one that reads as though the LGU filed
        // nothing for four years.
        //
        // The cap still applies from the other end: once there are more years
        // on file than fit, the chart shows the most recent $years of them.
        $first = LeaveRequest::min('date_filed');
        $earliest = $first === null
            ? $latest
            : max((int) Carbon::parse($first)->year, $latest - $years + 1);

        $labels = [];
        for ($y = $earliest; $y <= $latest; $y++) {
            $labels[] = (string) $y;
        }

        $rows = LeaveRequest::query()
            ->whereBetween('date_filed', [
                Carbon::create($earliest)->startOfYear(), Carbon::create($latest)->endOfYear(),
            ])
            ->get(['leave_type_id', 'date_filed']);

        return $this->build(
            $this->palette($latest), $labels,
            $rows->groupBy(fn ($r) => (int) $r->date_filed->year - $earliest),
            collect(), '',
        );
    }

    /**
     * Turn grouped rows into one line per slot, with the axis they share.
     *
     * @param  Collection<int, mixed>  $grouped  index in $labels => rows
     */
    private function build(Collection $palette, array $labels, Collection $grouped,
        Collection $previous, string $comparedWith): array
    {
        $slots = $palette->pluck('key')->unique()->values();
        $counts = [];
        foreach ($slots as $key) {
            $counts[$key] = array_fill(0, count($labels), 0);
        }

        foreach ($grouped as $index => $rows) {
            if ($index < 0 || $index >= count($labels)) {
                continue;
            }
            foreach ($rows as $row) {
                $key = $this->slotFor($palette, (int) $row->leave_type_id)['key'];
                $counts[$key][$index]++;
            }
        }

        // One scale for every line. Two would be a second axis, and a second
        // axis is a chart where any two lines can be made to cross by choosing
        // the scales.
        $peak = 0;
        foreach ($counts as $points) {
            $peak = max($peak, ...$points);
        }
        $top = max(4, (int) (ceil(max(1, $peak) / 4) * 4));

        // Last year's figure per slot, for the breakdown beside the chart.
        $before = [];
        foreach ($previous as $typeId => $total) {
            $key = $this->slotFor($palette, (int) $typeId)['key'];
            $before[$key] = ($before[$key] ?? 0) + (int) $total;
        }

        $series = [];
        foreach ($palette as $entry) {
            if (! isset($counts[$entry['key']])) {
                continue;
            }
            $points = $counts[$entry['key']];
            $total = array_sum($points);

            // A rise from nothing is not a percentage. "+1344%" against a base
            // of one application is a number that means nothing and reads as
            // though it means a great deal.
            $was = $before[$entry['key']] ?? 0;
            $delta = null;
            if ($comparedWith !== '' && $was > 0) {
                $delta = round(($total - $was) / $was * 100);
            }

            $series[] = [
                'key' => $entry['key'],
                'name' => $entry['name'],
                'points' => $points,
                'total' => $total,
                'was' => $was,
                'delta' => $delta,
                'compared_with' => $comparedWith,
            ];
        }

        // Drawn in slot order so the colours stay put, but listed in the
        // breakdown by size, which is what a list is for.
        $breakdown = $series;
        usort($breakdown, fn ($a, $b) => [$b['total'], $a['name']] <=> [$a['total'], $b['name']]);

        return [
            'labels' => $labels,
            'top' => $top,
            'series' => $series,
            'breakdown' => $breakdown,
            'total' => array_sum(array_column($series, 'total')),
        ];
    }
}
