<?php

namespace App\Services;

use App\Models\Department;
use App\Models\EmployeeProfile;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Builds role-scoped LEAVE dashboard datasets (counters + chart series).
 *
 * Security figures moved to SecurityDashboardService. The intrusion trend used
 * to live here because the plain Dashboard drew it too; it no longer does, and
 * a leave service holding one security query was the shape that let the
 * duplicate exist in the first place.
 */
class DashboardService
{
    /**
     * Statuses that are still waiting on somebody. Everything the workflow can
     * be sitting in before a decision is made — kept in one place so the
     * counters, the chart and the KPI cannot drift apart.
     */
    public const OPEN_STATUSES = ['pending', 'dept_review', 'hr_review', 'final_review', 'returned'];

    /**
     * A leave balance is "running low" at this many days or fewer.
     *
     * Absolute, not a proportion. The warning used to fire under 65% remaining,
     * which treats 65% of three days and 65% of fifteen as the same situation;
     * they are not, and only one of them is worth interrupting somebody over.
     */
    public const LOW_BALANCE_DAYS = 3;

    /** An application waiting longer than this has been waiting too long. */
    public const STALE_AFTER_DAYS = 5;

    /** An office with this share of its staff away at once cannot function. */
    public const COVERAGE_RISK = 0.4;

    /**
     * Two panes, gated separately, and somebody may hold both.
     *
     *   · leave.view-own          — their own credits and applications. An HR
     *                               officer files leave like anybody else, so
     *                               this is theirs too.
     *   · leave.requests.view-all — everyone's, for whoever has authority over
     *                               it: HR, the Mayor, the Vice Mayor.
     *
     * The second gate is the correction. These aggregates used to hang off
     * `users.manage` / `security.dashboard`, which is held only by the System
     * Administrator — who holds no leave permission at all. So the one role
     * with no business reading leave figures was the only role that could, and
     * the three roles with authority over leave saw nothing. Moving the gate
     * fixes both halves without adding a permission.
     *
     * The System Administrator is sent to the Security Dashboard instead; see
     * DashboardController. The sidebar is untouched either way.
     */
    public function forUser(User $user): array
    {
        $data = [];

        if ($user->hasPermission('leave.view-own')) {
            $data['mine'] = $this->ownPane($user);
        }

        if ($user->hasPermission('leave.requests.view-all')) {
            $data['management'] = $this->managementPane();
        } elseif ($user->hasPermission('leave.review.department')) {
            // A department head gets the same pane scoped to the one office
            // they head — never `leave.requests.view-all`, which is the whole
            // municipality. If they head no office there is nothing to show,
            // and the pane is absent rather than empty.
            $data['department'] = $this->departmentPane($user);
        }

        return $data;
    }

    // =====================================================================
    //  My leave — the employee's dashboard, and HR's first tab
    // =====================================================================

    /**
     * One person's own leave. No charts beyond the credit bars: "how many days
     * do I have left" is a number, and plotting it would be decoration.
     */
    public function ownPane(User $user): array
    {
        $balances = LeaveBalance::with('leaveType')
            ->where('user_id', $user->id)
            ->get()
            ->sortBy(fn ($b) => $b->leaveType?->id ?? PHP_INT_MAX)
            ->values();

        $open = LeaveRequest::where('user_id', $user->id)
            ->whereIn('status', self::OPEN_STATUSES)
            ->orderBy('date_filed')
            ->get(['date_filed']);

        $takenThisYear = LeaveRequest::where('user_id', $user->id)
            ->where('status', 'approved')
            ->whereBetween('start_date', [now()->startOfYear(), now()->endOfYear()])
            ->get(['working_days']);

        return [
            'kpis' => [
                $this->balanceKpi($balances, 'VL', 'Vacation left', 'sun'),
                $this->balanceKpi($balances, 'SL', 'Sick left', 'pulse'),
                [
                    'label' => 'Waiting on a decision',
                    'value' => $open->count(),
                    'sub' => $open->isEmpty()
                        ? 'nothing outstanding'
                        : 'oldest filed '.$this->plural((int) $open->first()->date_filed->diffInDays(now()), 'day').' ago',
                    'icon' => 'hour',
                    'tone' => $open->isEmpty() ? 'good' : 'warn',
                ],
                [
                    'label' => 'Taken this year',
                    'value' => $this->trim((float) $takenThisYear->sum('working_days')),
                    'sub' => 'days, across '.$this->plural($takenThisYear->count(), 'request'),
                    'icon' => 'calchk',
                    'tone' => 'info',
                ],
            ],
            'balances' => $balances->map(fn ($b) => $this->balanceRow($b))->all(),
            'requests' => LeaveRequest::with('leaveType')
                ->where('user_id', $user->id)->latest()->limit(8)->get(),
            'credit_history' => $user->leaveHistory()->with('leaveType')->latest()->limit(100)->get(),
        ];
    }

    /**
     * One credit bar, in whichever of its five states applies.
     *
     * The fifth is why this is a method and not an inline percentage: a type
     * with nothing accrued at all — Terminal Leave, or Maternity for somebody
     * it does not apply to — divides by zero, and the bar rendered `NaN%` wide.
     * "Not accrued" is also a different claim from "none left", so it gets its
     * own state rather than being flattened into an empty bar.
     */
    private function balanceRow(LeaveBalance $balance): array
    {
        $used = (float) $balance->used;
        $left = (float) $balance->balance;
        $total = $used + $left;

        return [
            'name' => $balance->leaveType?->name ?? 'Unknown',
            'code' => $balance->leaveType?->code ?? '',
            'used' => $used,
            'left' => $left,
            'total' => $total,
            'used_pct' => $total > 0 ? round($used / $total * 100, 1) : 0,
            'left_pct' => $total > 0 ? round($left / $total * 100, 1) : 0,
            'state' => match (true) {
                $total <= 0 => 'none',
                $left <= 0 => 'spent',
                $left <= self::LOW_BALANCE_DAYS => 'low',
                default => 'ok',
            },
            'readout' => match (true) {
                $total <= 0 => 'not accrued',
                $left <= 0 => 'none left',
                default => $this->trim($left).' left',
            },
        ];
    }

    private function balanceKpi($balances, string $code, string $label, string $icon): array
    {
        $balance = $balances->first(fn ($b) => $b->leaveType?->code === $code);
        $left = (float) ($balance->balance ?? 0);
        $earned = (float) ($balance->earned ?? 0);

        if ($balance === null || $earned <= 0) {
            return [
                'label' => $label, 'value' => '—', 'icon' => $icon, 'tone' => 'info',
                'sub' => 'no credits accrued',
            ];
        }

        return [
            'label' => $label,
            'value' => $this->trim($left),
            'sub' => 'of '.$this->trim($earned).' earned',
            'icon' => $icon,
            'tone' => match (true) {
                $left <= 0 => 'bad',
                $left <= self::LOW_BALANCE_DAYS => 'warn',
                default => 'good',
            },
        ];
    }

    // =====================================================================
    //  My office — the department head's second pane
    // =====================================================================

    /**
     * The management pane, narrowed to the one office this user heads.
     *
     * Same questions as HR's, asked about a single department: who is waiting
     * on me, who is away today, and can the office still function next
     * fortnight. Nothing municipality-wide appears, which is the point of
     * scoping the role rather than widening it.
     */
    public function departmentPane(User $user): ?array
    {
        $office = Department::where('head_user_id', $user->id)->first();

        if ($office === null) {
            return null;
        }

        $staff = EmployeeProfile::where('department_id', $office->id)->pluck('user_id');
        $queue = $this->waitingQueue(6, $staff);
        $onLeave = $this->onLeaveToday($staff);

        return [
            'office' => $office->name,
            'headcount' => $staff->count(),
            'kpis' => [
                [
                    'label' => 'Waiting on me',
                    'value' => $queue['mine'],
                    'sub' => $queue['mine'] > 0 ? 'awaiting your recommendation' : 'nothing to recommend',
                    'icon' => 'inbox',
                    'tone' => $queue['mine'] > 0 ? 'warn' : 'good',
                ],
                [
                    'label' => 'Away today',
                    'value' => $onLeave,
                    'sub' => 'of '.$this->plural($staff->count(), 'person').' in the office',
                    'icon' => 'walk',
                    'tone' => 'info',
                ],
                [
                    'label' => 'Filed this month',
                    'value' => LeaveRequest::whereIn('user_id', $staff)
                        ->whereBetween('date_filed', [now()->startOfMonth(), now()->endOfMonth()])->count(),
                    'sub' => now()->format('F Y'),
                    'icon' => 'file',
                    'tone' => 'info',
                ],
                [
                    'label' => 'Open applications',
                    'value' => $queue['total'],
                    'sub' => 'anywhere in the workflow',
                    'icon' => 'gavel',
                    'tone' => 'info',
                ],
            ],
            'worklist' => $queue['rows'],
            'coverage' => collect($this->coverageRisk())->firstWhere('office', $office->name),
            'types' => $this->mostAppliedTypes(
                now()->startOfYear(), now()->endOfYear(), 'this year', $staff
            ),
        ];
    }

    /** How many of these people are on approved leave today. */
    private function onLeaveToday($userIds): int
    {
        return LeaveRequest::whereIn('user_id', $userIds)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', today())
            ->whereDate('end_date', '>=', today())
            ->distinct()->count('user_id');
    }

    // =====================================================================
    //  Leave management — HR's second tab, and the approvers'
    // =====================================================================

    public function managementPane(): array
    {
        $outcome = $this->applicationsByOutcome((int) now()->year);
        $onLeave = $this->onLeaveWindows();
        $queue = $this->waitingQueue();
        $decided = $this->decisionsThisMonth();

        $thisMonth = $outcome['months'][now()->month - 1]['total'];
        $lastYear = $this->filedInMonth(now()->copy()->subYear());

        return [
            'kpis' => [
                [
                    'label' => 'Waiting on a decision',
                    'value' => $queue['total'],
                    'lead' => $queue['stale'] > 0 ? (string) $queue['stale'] : null,
                    'sub' => $queue['stale'] > 0
                        ? 'older than '.self::STALE_AFTER_DAYS.' days'
                        : 'none older than '.self::STALE_AFTER_DAYS.' days',
                    'icon' => 'inbox',
                    'tone' => $queue['stale'] > 0 ? 'warn' : ($queue['total'] > 0 ? 'info' : 'good'),
                ],
                [
                    'label' => 'On leave today',
                    'value' => $onLeave['today'],
                    'sub' => 'across '.$this->plural($onLeave['offices_today'], 'office'),
                    'icon' => 'walk',
                    'tone' => 'info',
                ],
                [
                    'label' => 'Filed this month',
                    'value' => $thisMonth,
                    'sub' => $lastYear.' in '.now()->copy()->subYear()->format('F Y'),
                    'icon' => 'file',
                    'tone' => 'info',
                ],
                [
                    'label' => 'Decided this month',
                    'value' => $decided['count'],
                    'sub' => $decided['median'] !== null
                        ? 'median '.$decided['median'].' days to decide'
                        : 'nothing decided yet',
                    'icon' => 'gavel',
                    'tone' => 'good',
                ],
            ],
            'outcome' => $outcome,
            'filed_by_month' => [
                'labels' => array_column($outcome['months'], 'label'),
                'data' => array_column($outcome['months'], 'total'),
            ],
            'types_month' => $this->mostAppliedTypes(now()->startOfMonth(), now()->endOfMonth(), 'this month'),
            'types_year' => $this->mostAppliedTypes(now()->startOfYear(), now()->endOfYear(), 'this year'),
            'departments' => $this->applicationsByDepartment(now()->startOfYear(), now()->endOfYear()),

            // The three additions. All read columns that already existed.
            'worklist' => $queue['rows'],
            'coverage' => $this->coverageRisk(),
            'mandatory' => $this->mandatoryLeaveCompliance(),
        ];
    }

    /** Applications filed in the calendar month $when falls in. */
    public function filedInMonth(CarbonInterface $when): int
    {
        return LeaveRequest::whereBetween('date_filed', [
            $when->copy()->startOfMonth(), $when->copy()->endOfMonth(),
        ])->count();
    }

    /**
     * The applications nobody has decided yet, oldest first.
     *
     * The counter says seven; this says which seven. A number tells an officer
     * that there is a backlog and leaves them to go and find it — the same
     * query, run twice, once by the dashboard and once by the person reading
     * it.
     */
    public function waitingQueue(int $limit = 6, $userIds = null): array
    {
        $open = LeaveRequest::with(['leaveType', 'user.employeeProfile'])
            ->whereIn('status', self::OPEN_STATUSES)
            ->when($userIds !== null, fn ($q) => $q->whereIn('user_id', $userIds))
            ->orderBy('date_filed')
            ->get();

        $rows = $open->take($limit)->map(function (LeaveRequest $request) {
            $age = (int) $request->date_filed->startOfDay()->diffInDays(now()->startOfDay());
            $profile = $request->user?->employeeProfile;

            return [
                'id' => $request->id,
                'reference' => $request->reference_no,
                'who' => $profile
                    ? trim($profile->last_name.', '.$profile->first_name)
                    : ($request->user?->name ?? 'Unknown'),
                'what' => ($request->leaveType?->name ?? 'Leave').' · '
                    .$request->start_date->format('j M')
                    .($request->start_date->isSameDay($request->end_date) ? '' : '–'.$request->end_date->format('j M')),
                'age' => $age,
                'stale' => $age > self::STALE_AFTER_DAYS,
            ];
        })->all();

        return [
            'total' => $open->count(),
            'stale' => $open->filter(
                fn ($r) => $r->date_filed->startOfDay()->diffInDays(now()->startOfDay()) > self::STALE_AFTER_DAYS
            )->count(),
            // Waiting specifically on the department step — what a head can act
            // on, as opposed to everything of theirs still in flight.
            'mine' => $open->where('status', LeaveRequest::STATUS_DEPT_REVIEW)->count(),
            'rows' => $rows,
        ];
    }

    /**
     * How many applications were decided this month, and how long it took.
     *
     * The median, not the mean: one application that sat for forty days while
     * somebody was on leave themselves drags an average far enough to make a
     * healthy month look broken.
     */
    public function decisionsThisMonth(): array
    {
        $decided = LeaveRequest::whereIn('status', ['approved', 'rejected'])
            ->whereNotNull('decided_at')
            ->whereBetween('decided_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->get(['date_filed', 'decided_at']);

        if ($decided->isEmpty()) {
            return ['count' => 0, 'median' => null];
        }

        $days = $decided
            ->map(fn ($r) => (float) $r->date_filed->startOfDay()->diffInDays($r->decided_at->startOfDay()))
            ->sort()->values();

        $middle = intdiv($days->count(), 2);
        $median = $days->count() % 2 === 1
            ? $days[$middle]
            : ($days[$middle - 1] + $days[$middle]) / 2;

        return ['count' => $decided->count(), 'median' => round($median, 1)];
    }

    /**
     * The worst single day of approved absence in each office over the next
     * fortnight, as a share of that office's headcount.
     *
     * The only forward-looking figure on the page. Everything else reports what
     * already happened, which cannot be acted on; four of six Treasury staff
     * being away in the same week can be, but only before the week arrives.
     *
     * The office is the applicant's CURRENT department, not the
     * `office_snapshot` on the application. The snapshot records where they
     * were when they filed, which is the right answer for the printed form and
     * the wrong one here: if somebody transferred last month, it is their new
     * office that will be a person short next week. The snapshot is the
     * fallback for an applicant with no employee record at all, so those
     * absences are still counted somewhere rather than dropped.
     */
    public function coverageRisk(int $days = 14): array
    {
        $from = today();
        $to = today()->addDays($days - 1);

        $requests = LeaveRequest::query()
            ->leftJoin('employee_profiles', 'employee_profiles.user_id', '=', 'leave_requests.user_id')
            ->leftJoin('departments', 'departments.id', '=', 'employee_profiles.department_id')
            ->where('leave_requests.status', 'approved')
            ->whereDate('leave_requests.start_date', '<=', $to)
            ->whereDate('leave_requests.end_date', '>=', $from)
            ->get([
                'leave_requests.user_id', 'leave_requests.start_date', 'leave_requests.end_date',
                'leave_requests.office_snapshot', 'departments.name as department_name',
            ]);

        $headcount = EmployeeProfile::query()
            ->leftJoin('departments', 'departments.id', '=', 'employee_profiles.department_id')
            ->selectRaw('departments.name as department_name, count(*) as total')
            ->groupBy('departments.name')
            ->pluck('total', 'department_name');

        // office => Y-m-d => [user ids]
        $byOffice = [];
        foreach ($requests as $request) {
            $office = $request->department_name ?: ($request->office_snapshot ?: 'Unassigned');
            $start = $request->start_date->lt($from) ? $from->copy() : $request->start_date->copy();
            $end = $request->end_date->gt($to) ? $to->copy() : $request->end_date->copy();

            for ($d = $start; $d->lte($end); $d->addDay()) {
                $byOffice[$office][$d->toDateString()][] = (int) $request->user_id;
            }
        }

        $rows = [];
        foreach (Department::orderBy('id')->get(['name']) as $department) {
            $rows[] = $this->coverageRow($department->name, $byOffice[$department->name] ?? [], (int) ($headcount[$department->name] ?? 0));
        }

        // An office that only exists in a snapshot — renamed, or since removed —
        // still has people away in it.
        foreach ($byOffice as $office => $daysOut) {
            if (! collect($rows)->contains('office', $office)) {
                $rows[] = $this->coverageRow($office, $daysOut, (int) ($headcount[$office] ?? 0));
            }
        }

        usort($rows, fn ($a, $b) => [$b['share'], $b['out']] <=> [$a['share'], $a['out']]);

        return $rows;
    }

    private function coverageRow(string $office, array $daysOut, int $headcount): array
    {
        $peak = 0;
        $when = null;

        foreach ($daysOut as $day => $ids) {
            $count = count(array_unique($ids));
            if ($count > $peak) {
                $peak = $count;
                $when = $day;
            }
        }

        $share = $headcount > 0 ? $peak / $headcount : 0.0;

        return [
            'office' => $office,
            'out' => $peak,
            'staff' => $headcount,
            'share' => round($share, 3),
            'pct' => (int) round($share * 100),
            'when' => $when ? Carbon::parse($when)->format('j M') : null,
            'at_risk' => $share >= self::COVERAGE_RISK,
        ];
    }

    /**
     * Mandatory (Forced) Leave that has not been taken.
     *
     * The CSC requires five days a year and they do not carry over, so an
     * employee who has filed none of theirs in November is about to lose them
     * and HR is the office accountable when that happens. Nothing in the system
     * tracked it.
     */
    public function mandatoryLeaveCompliance(): array
    {
        $type = LeaveType::where('code', 'FL')->first();

        if ($type === null) {
            return ['tracked' => false, 'outstanding' => 0, 'months_left' => 0, 'by_office' => []];
        }

        $balances = LeaveBalance::query()
            ->leftJoin('employee_profiles', 'employee_profiles.user_id', '=', 'leave_balances.user_id')
            ->leftJoin('departments', 'departments.id', '=', 'employee_profiles.department_id')
            ->where('leave_balances.leave_type_id', $type->id)
            ->get([
                'leave_balances.used', 'leave_balances.earned',
                'departments.name as department_name',
            ]);

        $outstanding = $balances->filter(fn ($b) => (float) $b->used <= 0 && (float) $b->earned > 0);

        $counts = $outstanding->countBy(fn ($b) => $b->department_name ?: 'Unassigned');

        $rows = Department::orderBy('id')->get(['name'])->map(fn ($d) => [
            'label' => $d->name,
            'name' => $d->name,
            'value' => (int) ($counts[$d->name] ?? 0),
            'note' => null,
            'muted' => false,
        ])->all();

        if (($counts['Unassigned'] ?? 0) > 0) {
            $rows[] = [
                'label' => 'Unassigned', 'name' => 'No department on record',
                'value' => (int) $counts['Unassigned'], 'note' => null, 'muted' => true,
            ];
        }

        usort($rows, function ($a, $b) {
            if ($a['muted'] !== $b['muted']) {
                return $a['muted'] <=> $b['muted'];
            }

            return [$b['value'], $a['label']] <=> [$a['value'], $b['label']];
        });

        return [
            'tracked' => true,
            'outstanding' => $outstanding->count(),
            'months_left' => 12 - (int) now()->month + 1,
            'by_office' => $rows,
        ];
    }

    // ------------------------------------------------------------- formatting

    /** 12.50 → "12.5", 9.00 → "9". Days are not currency. */
    private function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') ?: '0';
    }

    private function plural(int $count, string $noun): string
    {
        return $count.' '.$noun.($count === 1 ? '' : 's');
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
        $todayIds = $byDay[now()->toDateString()] ?? [];

        return [
            'today' => count($todayIds),
            'offices_today' => $this->officesOf($todayIds),
            'week' => $this->windowFrom($byDay, $weekStart, $weekEnd),
            'month' => $this->windowFrom($byDay, $monthStart, $monthEnd),
        ];
    }

    /**
     * How many distinct offices a set of employees belongs to.
     *
     * Four people away is one number; four people away from one office and four
     * spread over four are different situations, and only the first is a
     * staffing problem.
     */
    private function officesOf(array $userIds): int
    {
        if ($userIds === []) {
            return 0;
        }

        return EmployeeProfile::whereIn('user_id', array_unique($userIds))
            ->whereNotNull('department_id')
            ->distinct()->count('department_id');
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
     * No colour slot. The rows carry no per-row hue any more: sort order
     * already encodes the ranking, so painting each row would repeat it in a
     * second channel and imply a category difference that is not there — and it
     * repainted the whole chart every time the ranking moved between the month
     * and the year view. The stylesheet gives the top row the system's violet
     * and everything else grey.
     */
    public function mostAppliedTypes(CarbonInterface $from, CarbonInterface $to, string $period = '', $userIds = null): array
    {
        $counts = LeaveRequest::query()
            ->whereBetween('date_filed', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when($userIds !== null, fn ($q) => $q->whereIn('user_id', $userIds))
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

}
