<?php

namespace App\Services;

use App\Models\AuthorizedDevice;
use App\Models\Department;
use App\Models\EmployeeProfile;
use App\Models\IntrusionLog;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
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

    public function forUser(User $user): array
    {
        $data = ['role' => $this->primaryRole($user), 'cards' => [], 'charts' => []];

        // System counters. These belong to whoever runs the installation, not to
        // whoever decides leave, so they are gated on their own permissions.
        if ($user->hasPermission('security.dashboard') || $user->hasPermission('users.manage')) {
            $data['cards'] += [
                'employees' => EmployeeProfile::count(),
                'pending_leaves' => LeaveRequest::whereIn('status', self::OPEN_STATUSES)->count(),
                'intrusions_today' => IntrusionLog::whereDate('created_at', today())->count(),
                'devices_online' => AuthorizedDevice::active()->where('last_active_at', '>', now()->subMinutes(5))->count(),
                'devices_offline' => AuthorizedDevice::active()->where(fn ($q) => $q->whereNull('last_active_at')->orWhere('last_active_at', '<=', now()->subMinutes(5)))->count(),
            ];
            $data['system_row'] = true;
        }

        if ($user->hasPermission('leave.requests.view-all') || $user->hasPermission('leave.certify.hr')) {
            $data['cards'] += [
                'total_requests' => LeaveRequest::count(),
                'approved' => LeaveRequest::where('status', 'approved')->count(),
                'departments' => Department::count(),
            ];
        }

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

        // The back-office analytics: whoever can see every application gets the
        // numbers about every application. That is HR, the Mayor and the Vice
        // Mayor — previously all three landed on the employee dashboard and saw
        // none of this.
        if ($user->hasPermission('leave.requests.view-all')) {
            $data['back_office'] = true;
            $data['bo_users'] = $this->registeredUsers();
            $data['bo_outcome'] = $this->applicationsByOutcome((int) now()->year);
            $data['bo_on_leave'] = $this->onLeaveWindows();
            $data['bo_types_month'] = $this->mostAppliedTypes(now()->startOfMonth(), now()->endOfMonth());
            $data['bo_types_year'] = $this->mostAppliedTypes(now()->startOfYear(), now()->endOfYear());
            $data['bo_departments'] = $this->applicationsByDepartment(now()->startOfYear(), now()->endOfYear());
        }

        return $data;
    }

    public function primaryRole(User $user): string
    {
        return app(\App\Services\Rbac\RbacService::class)->userRoleSlugs($user)->first() ?? 'employee';
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
     * Slice the per-day map down to one window.
     *
     * "Distinct" is the number of *people*, not the sum of the daily counts —
     * one employee off for five days is one employee. It cannot be recovered
     * from the counts alone, which is why the map carries the IDs.
     */
    private function windowFrom(array $byDay, CarbonInterface $from, CarbonInterface $to): array
    {
        $today = now()->toDateString();
        $days = [];
        $everyone = [];

        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $key = $d->toDateString();
            $ids = $byDay[$key] ?? [];
            $everyone = array_merge($everyone, $ids);
            $days[] = [
                'date' => $d->copy(),
                'count' => count($ids),
                'future' => $key > $today,
            ];
        }

        return [
            'days' => $days,
            'distinct' => count(array_unique($everyone)),
            'peak' => $days ? max(array_column($days, 'count')) : 0,
        ];
    }

    /**
     * Leave types ranked by how many applications name them, in a window.
     *
     * Applications, not days: "most applied for" and "most days taken" are
     * different questions and only one of them was asked.
     */
    public function mostAppliedTypes(CarbonInterface $from, CarbonInterface $to, int $limit = 6): array
    {
        $rows = LeaveRequest::query()
            ->whereBetween('date_filed', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->selectRaw('leave_type_id, count(*) as total')
            ->groupBy('leave_type_id')
            ->orderByDesc('total')
            ->limit($limit)
            ->with('leaveType:id,name,code')
            ->get();

        $overall = (int) $rows->sum('total');
        $top = (int) ($rows->first()->total ?? 0);

        return $rows->map(fn ($row) => [
            'name' => $row->leaveType?->name ?? 'Unknown',
            'code' => $row->leaveType?->code ?? '—',
            'total' => (int) $row->total,
            'width' => $top > 0 ? round((int) $row->total / $top * 100, 1) : 0,
            'share' => $overall > 0 ? round((int) $row->total / $overall * 100) : 0,
        ])->all();
    }

    /**
     * Applications per department, with a per-head figure beside the count.
     *
     * The raw count only says which department is biggest. Per head says whether
     * its people actually file more often, which is the question somebody would
     * act on. Employees with no department are reported as "Unassigned" rather
     * than dropped — a silent gap looks like nothing is wrong.
     */
    public function applicationsByDepartment(CarbonInterface $from, CarbonInterface $to): array
    {
        $filings = LeaveRequest::query()
            ->join('employee_profiles', 'employee_profiles.user_id', '=', 'leave_requests.user_id')
            ->whereBetween('leave_requests.date_filed', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->selectRaw('employee_profiles.department_id as department_id, count(*) as total')
            ->groupBy('employee_profiles.department_id')
            ->pluck('total', 'department_id');

        if ($filings->isEmpty()) {
            return [];
        }

        $staff = EmployeeProfile::query()
            ->selectRaw('department_id, count(*) as total')
            ->groupBy('department_id')
            ->pluck('total', 'department_id');

        $names = Department::pluck('name', 'id');
        $top = (int) $filings->max();

        $rows = $filings->map(function ($total, $departmentId) use ($names, $staff, $top) {
            $headcount = (int) ($staff[$departmentId] ?? 0);

            return [
                'name' => $names[$departmentId] ?? 'Unassigned',
                'unassigned' => ! isset($names[$departmentId]),
                'total' => (int) $total,
                'staff' => $headcount,
                'per_head' => $headcount > 0 ? round((int) $total / $headcount, 1) : null,
                'width' => $top > 0 ? round((int) $total / $top * 100, 1) : 0,
            ];
        })->values()->all();

        usort($rows, fn ($a, $b) => $b['total'] <=> $a['total']);

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
