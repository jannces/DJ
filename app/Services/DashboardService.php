<?php

namespace App\Services;

use App\Models\Department;
use App\Models\EmployeeProfile;
use App\Models\IntrusionLog;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/** Builds role-scoped dashboard datasets (counters + chart series). */
class DashboardService
{
    /**
     * Statuses that are still waiting on somebody. Everything the workflow can
     * be sitting in before a decision is made — kept in one place so the
     * counters, the chart and the KPI cannot drift apart.
     */
    public const OPEN_STATUSES = ['pending', 'dept_review', 'hr_review', 'final_review', 'returned'];

    /**
     * How many distinct chart colours the stylesheet defines. Slots are keyed
     * to a row's own primary key rather than to its rank or its position in the
     * list, both of which move: a leave type keeps its colour when the ranking
     * shifts between the month and the year view, or when a retired type enters
     * or leaves the chart.
     */
    public const TONES = 8;

    public function forUser(User $user): array
    {
        $data = ['cards' => []];

        // Employee self-service cards. The dashboard is now the single place an
        // employee sees leave credits, so it also carries the credit ledger that
        // the retired "My Balances" page used to show — same queries, one home.
        if ($user->hasPermission('leave.view-own')) {
            $data['cards'] += [
                'my_pending' => LeaveRequest::where('user_id', $user->id)->whereIn('status', self::OPEN_STATUSES)->count(),
                'my_approved' => LeaveRequest::where('user_id', $user->id)->where('status', 'approved')->count(),
                'my_rejected' => LeaveRequest::where('user_id', $user->id)->where('status', 'rejected')->count(),
            ];
            $data['my_balances'] = LeaveBalance::with('leaveType')->where('user_id', $user->id)->get();
            $data['my_credit_history'] = $user->leaveHistory()->with('leaveType')->latest()->limit(100)->get();
            $data['my_requests'] = LeaveRequest::with('leaveType')
                ->where('user_id', $user->id)->latest()->limit(8)->get();
        }

        // Leave analytics for the administrator's Dashboard — the plain one,
        // not the Security Dashboard, which rendered two counters before this.
        //
        // Gated on the administration permissions rather than on a leave one:
        // these are read-only aggregates about the installation, and the
        // administrator holds none of the leave permissions.
        if ($user->hasPermission('security.dashboard') || $user->hasPermission('users.manage')) {
            $data['leave_analytics'] = true;
            $data['an_users'] = $this->registeredUsers();
            $data['an_outcome'] = $this->applicationsByOutcome((int) now()->year);
            $data['an_on_leave'] = $this->onLeaveWindows();
            $data['an_types_month'] = $this->mostAppliedTypes(now()->startOfMonth(), now()->endOfMonth(), 'this month');
            $data['an_types_year'] = $this->mostAppliedTypes(now()->startOfYear(), now()->endOfYear(), 'this year');
            $data['an_departments'] = $this->applicationsByDepartment(now()->startOfYear(), now()->endOfYear());
        }

        return $data;
    }

    /** Accounts on the system, split into those with an employee record and those without. */
    public function registeredUsers(): array
    {
        $total = User::count();
        $employees = EmployeeProfile::count();

        return [
            'total' => $total,
            'employees' => $employees,
            'officers' => max(0, $total - $employees),
        ];
    }

    /**
     * Applications filed in each month of $year, split by how they ended up.
     *
     * A column is "the applications filed in this month", so the columns add up
     * to the year and to the "filed this month" counter — no third definition to
     * keep in step. Cancelled applications are left out: they are a withdrawal,
     * not an outcome anybody decided.
     *
     * One query, bucketed in PHP. A count per month per status would be
     * thirty-six round trips for a few hundred rows, and MONTH() is not portable
     * to the SQLite the test suite runs on.
     *
     * @return array{months:array<int,array<string,mixed>>, totals:array<string,int>}
     */
    public function applicationsByOutcome(int $year): array
    {
        $rows = LeaveRequest::query()
            ->whereBetween('date_filed', [
                Carbon::create($year, 1, 1)->startOfDay(),
                Carbon::create($year, 12, 31)->endOfDay(),
            ])
            ->get(['date_filed', 'status']);

        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $months[$m] = [
                'month' => $m,
                'label' => Carbon::create($year, $m, 1)->format('M'),
                'approved' => 0, 'rejected' => 0, 'pending' => 0, 'total' => 0,
            ];
        }

        $totals = ['approved' => 0, 'rejected' => 0, 'pending' => 0, 'total' => 0];

        foreach ($rows as $row) {
            $bucket = match ($row->status) {
                'approved' => 'approved',
                'rejected' => 'rejected',
                'cancelled' => null,
                default => 'pending',
            };
            if ($bucket === null) {
                continue;
            }

            $m = (int) $row->date_filed->format('n');
            $months[$m][$bucket]++;
            $months[$m]['total']++;
            $totals[$bucket]++;
            $totals['total']++;
        }

        return ['months' => array_values($months), 'totals' => $totals];
    }

    /**
     * Employees on approved leave, for today, this week and this month.
     *
     * All three come out of a single overlap query. Leave is a date *range*, so
     * asking the database for a count per day is thirty-one round trips to
     * rebuild something a few dozen rows already contain; the expansion belongs
     * in PHP, where a loop costs nothing.
     *
     * The window is stretched to cover both the calendar month and the current
     * week, because a week straddles the month boundary twice a year.
     */
    public function onLeaveWindows(): array
    {
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();
        $weekStart = now()->startOfWeek();
        $weekEnd = now()->endOfWeek();

        $from = $weekStart->lt($monthStart) ? $weekStart->copy() : $monthStart->copy();
        $to = $weekEnd->gt($monthEnd) ? $weekEnd->copy() : $monthEnd->copy();

        $byDay = $this->onLeaveByDay($from, $to);

        return [
            'today' => count($byDay[now()->toDateString()] ?? []),
            'week' => $this->windowFrom($byDay, $weekStart, $weekEnd),
            'month' => $this->windowFrom($byDay, $monthStart, $monthEnd),
        ];
    }

    /**
     * The user IDs on approved leave on each day of the window.
     *
     * @return array<string,array<int,int>> keyed Y-m-d
     */
    public function onLeaveByDay(CarbonInterface $from, CarbonInterface $to): array
    {
        $days = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $days[$d->toDateString()] = [];
        }

        $rows = LeaveRequest::query()
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $to->toDateString())
            ->whereDate('end_date', '>=', $from->toDateString())
            ->get(['user_id', 'start_date', 'end_date']);

        foreach ($rows as $row) {
            $start = $row->start_date->lt($from) ? $from->copy() : $row->start_date->copy();
            $end = $row->end_date->gt($to) ? $to->copy() : $row->end_date->copy();

            for ($d = $start; $d->lte($end); $d->addDay()) {
                $days[$d->toDateString()][] = (int) $row->user_id;
            }
        }

        return $days;
    }

    /**
     * Reduce the per-day map to the two figures one window reports.
     *
     * "Distinct" is the number of *people*, not the sum of the daily counts —
     * one employee off for five days is one employee. It cannot be recovered
     * from the counts alone, which is why the map carries the IDs. The peak is
     * the worst single day, which is the figure somebody staffing an office
     * actually needs; the day-by-day series it came from is not returned,
     * because nothing renders it.
     */
    private function windowFrom(array $byDay, CarbonInterface $from, CarbonInterface $to): array
    {
        $everyone = [];
        $peak = 0;

        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $ids = $byDay[$d->toDateString()] ?? [];
            $everyone = array_merge($everyone, $ids);
            $peak = max($peak, count($ids));
        }

        return [
            'distinct' => count(array_unique($everyone)),
            'peak' => $peak,
        ];
    }

    /**
     * Every leave type, ranked by how many applications name it.
     *
     * Every type, not just the busy ones: a leave nobody filed for is a real
     * answer to "what do people apply for", and a chart that silently omits it
     * cannot be told apart from one where the type does not exist.
     *
     * A retired type still appears while it has applications in the window.
     * Filtering on `active` alone would take its history with it, and the bars
     * would then add up to less than the outcome chart on the same page — two
     * figures for the same applications, disagreeing, with nothing on screen to
     * explain why.
     *
     * Applications, not days: "most applied for" and "most days taken" are
     * different questions and only one of them was asked.
     *
     * The colour slot is keyed to the type's own id — not to its rank, and not
     * to its position in this list, which shifts as retired types come and go
     * between windows. So a type keeps its colour whichever view you are on.
     * There are more leave types than slots, so two can share a colour, which
     * is harmless: every column is labelled with its code and its count.
     */
    public function mostAppliedTypes(CarbonInterface $from, CarbonInterface $to, string $period = ''): array
    {
        $counts = LeaveRequest::query()
            ->whereBetween('date_filed', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->selectRaw('leave_type_id, count(*) as total')
            ->groupBy('leave_type_id')
            ->pluck('total', 'leave_type_id');

        $types = LeaveType::query()
            ->where(fn ($q) => $q->where('active', true)->orWhereIn('id', $counts->keys()))
            ->orderBy('id')
            ->get(['id', 'code', 'name', 'active']);
        $overall = (int) $counts->sum();

        $rows = $types->map(function ($type) use ($counts, $overall, $period) {
            $value = (int) ($counts[$type->id] ?? 0);
            $share = $overall > 0 ? round($value / $overall * 100) : 0;

            return [
                'label' => $type->code,
                'name' => $type->name.($type->active ? '' : ' (retired)'),
                'value' => $value,
                'note' => $value > 0
                    ? $share.'% of applications '.$period
                    : 'Nothing filed '.$period,
                'tone' => $type->id % self::TONES,
                'muted' => ! $type->active,
            ];
        })->all();

        usort($rows, fn ($a, $b) => [$b['value'], $a['label']] <=> [$a['value'], $b['label']]);

        return $rows;
    }

    /**
     * Every department, ranked by applications filed, with a per-head figure in
     * the readout.
     *
     * The raw count only says which department is biggest. Per head says
     * whether its people actually file more often, which is the question
     * somebody would act on. A department that filed nothing still gets a
     * column — an absent bar is an answer; a missing one is a blank.
     *
     * The join is a LEFT join on purpose. An inner one drops every application
     * filed by somebody with no employee_profiles row at all — not the same
     * gap as a profile with no department, but just as invisible, and it made
     * the columns quietly add up to less than the applications on record.
     * Both cases now land in "Unassigned", which appears only when there is
     * something to report: a silent gap looks like nothing is wrong.
     */
    public function applicationsByDepartment(CarbonInterface $from, CarbonInterface $to): array
    {
        $filings = LeaveRequest::query()
            ->leftJoin('employee_profiles', 'employee_profiles.user_id', '=', 'leave_requests.user_id')
            ->whereBetween('leave_requests.date_filed', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->selectRaw('employee_profiles.department_id as department_id, count(*) as total')
            ->groupBy('employee_profiles.department_id')
            ->pluck('total', 'department_id');

        $staff = EmployeeProfile::query()
            ->selectRaw('department_id, count(*) as total')
            ->groupBy('department_id')
            ->pluck('total', 'department_id');

        $departments = Department::orderBy('id')->get(['id', 'code', 'name']);

        $rows = $departments->map(function ($department) use ($filings, $staff) {
            $value = (int) ($filings[$department->id] ?? 0);
            $headcount = (int) ($staff[$department->id] ?? 0);
            $perHead = $headcount > 0 ? round($value / $headcount, 1) : null;

            return [
                'label' => $department->code ?: $department->name,
                'name' => $department->name,
                'value' => $value,
                'staff' => $headcount,
                'per_head' => $perHead,
                'note' => $headcount.($headcount === 1 ? ' employee' : ' employees')
                    .($perHead !== null ? ' · '.$perHead.' per head' : ''),
                'tone' => $department->id % self::TONES,
                'muted' => false,
            ];
        })->all();

        // "" is how a null department_id comes back from pluck.
        $strayFilings = (int) ($filings[''] ?? 0);
        $strayStaff = (int) ($staff[''] ?? 0);
        if ($strayFilings > 0 || $strayStaff > 0) {
            $rows[] = [
                'label' => 'None',
                'name' => 'Unassigned',
                'value' => $strayFilings,
                'staff' => $strayStaff,
                'per_head' => $strayStaff > 0 ? round($strayFilings / $strayStaff, 1) : null,
                'note' => $strayStaff.($strayStaff === 1 ? ' employee' : ' employees')
                    .' with no department on record',
                'tone' => null,
                'muted' => true,
            ];
        }

        usort($rows, function ($a, $b) {
            // Unassigned is a data problem, not a department: it sorts last
            // whatever its count, so it never reads as the busiest office.
            if ($a['muted'] !== $b['muted']) {
                return $a['muted'] <=> $b['muted'];
            }

            return [$b['value'], $a['label']] <=> [$a['value'], $b['label']];
        });

        return $rows;
    }

    /**
     * Intrusion events per day for the last seven days.
     *
     * Lives here because the Security Dashboard is the only page that draws it —
     * the plain Dashboard used to draw the identical series from its own copy of
     * this loop.
     */
    public function intrusionsByDay(int $days = 7): array
    {
        $from = today()->subDays($days - 1);

        $counts = IntrusionLog::query()
            ->where('created_at', '>=', $from->copy()->startOfDay())
            ->selectRaw('date(created_at) as day, count(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $labels = [];
        $data = [];
        for ($d = $from->copy(); $d->lte(today()); $d->addDay()) {
            $labels[] = $d->format('D');
            $data[] = (int) ($counts[$d->toDateString()] ?? 0);
        }

        return ['labels' => $labels, 'data' => $data];
    }
}
