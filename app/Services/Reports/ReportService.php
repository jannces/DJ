<?php

namespace App\Services\Reports;

use App\Models\ActivityLog;
use App\Models\AuditLog;
use App\Models\BlockedIp;
use App\Models\Department;
use App\Models\FailedLogin;
use App\Models\IntrusionLog;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Support\Carbon;

/**
 * Produces the report datasets. Each returns a uniform structure
 * {title, columns, rows} that a single set of PDF/XLSX/CSV exporters consumes.
 * Which of them a given account may run is declared in CATALOGUE below.
 */
class ReportService
{
    /**
     * The report catalogue: what each one is called, who may run it, and which
     * side of the system it belongs to.
     *
     * The permission is the point. Before this every report was gated on
     * `reports.generate` alone, which the System Administrator holds — so an
     * account with no leave permission at all could open, and export, every
     * employee's leave record through the reports module. Each report now
     * names the permission its *subject* requires, not merely the right to run
     * reports in general, and the controller checks it before building
     * anything. The grouping is what the page and the menu read.
     */
    public const CATALOGUE = [
        'employee-leave' => [
            'title' => 'Employee Leave Report',
            'about' => 'Every application filed in the period',
            'permission' => 'leave.requests.view-all', 'group' => 'leave', 'scope' => self::SCOPE_RANGE,
        ],
        'leave-type-summary' => [
            // Was "Annual Report". Its content is a summary per leave type,
            // which is a real question — but "Annual" is a *period*, and a card
            // that carries a period control cannot also be named after one. It
            // honours the period now instead of always being a year.
            'title' => 'Leave Type Summary',
            'about' => 'Filed, approved and disapproved per type',
            'permission' => 'leave.requests.view-all', 'group' => 'leave', 'scope' => self::SCOPE_RANGE,
        ],
        'department' => [
            'title' => 'Department Report',
            'about' => 'Applications and headcount per office',
            'permission' => 'leave.requests.view-all', 'group' => 'leave', 'scope' => self::SCOPE_RANGE,
        ],
        'pending' => [
            'title' => 'Pending Applications',
            'about' => 'Still awaiting a decision, oldest first',
            'permission' => 'leave.requests.view-all', 'group' => 'leave', 'scope' => self::SCOPE_NONE,
        ],
        'mandatory-leave' => [
            'title' => 'Mandatory Leave Compliance',
            'about' => 'Who has not filed their five CSC days',
            'permission' => 'leave.requests.view-all', 'group' => 'leave', 'scope' => self::SCOPE_YEAR,
        ],
        'leave-balance' => [
            'title' => 'Leave Balance Report',
            'about' => 'Earned, used and remaining per employee',
            'permission' => 'leave.requests.view-all', 'group' => 'leave', 'scope' => self::SCOPE_NONE,
        ],
        // The same three questions an office head asks, answered for the one
        // office they head. They reuse the builders above rather than growing
        // a second version that could drift from the first -- the only
        // difference is that the department is supplied rather than chosen,
        // and ReportController supplies it from the record, not the request.
        'my-office-leave' => [
            'title' => 'Leave in my office',
            'about' => 'Every application filed in the period',
            'permission' => 'reports.department', 'group' => 'department', 'scope' => self::SCOPE_RANGE,
        ],
        'my-office-pending' => [
            'title' => 'Waiting on me',
            'about' => 'Applications not yet recommended',
            'permission' => 'reports.department', 'group' => 'department', 'scope' => self::SCOPE_NONE,
        ],
        'my-office-balances' => [
            'title' => 'Leave balances in my office',
            'about' => 'Credits remaining, per person',
            'permission' => 'reports.department', 'group' => 'department', 'scope' => self::SCOPE_NONE,
        ],

        'intrusion' => [
            'title' => 'Intrusion Report',
            'about' => 'Every detection, with its rule and target',
            'permission' => 'reports.security', 'group' => 'security', 'scope' => self::SCOPE_RANGE,
        ],
        'audit' => [
            'title' => 'Audit Report',
            'about' => 'Who changed what, and the role they held',
            'permission' => 'reports.security', 'group' => 'security', 'scope' => self::SCOPE_RANGE,
        ],
        'blocked-login' => [
            'title' => 'Blocked Login Report',
            'about' => 'Failed sign-ins and the IPs blocked for them',
            'permission' => 'reports.security', 'group' => 'security', 'scope' => self::SCOPE_RANGE,
        ],
        'user-activity' => [
            'title' => 'User Activity Report',
            'about' => 'Pages reached, by whom, from where',
            'permission' => 'reports.security', 'group' => 'security', 'scope' => self::SCOPE_RANGE,
        ],
    ];

    /**
     * How much of a period a report has.
     *
     * Not every report has one, and offering a control that changes nothing is
     * worse than offering none: Department and Leave Balance both carried the
     * month and year pickers and both ignored them, so a file captioned
     * "August 2026" could contain every row ever recorded. Department now reads
     * the period; a balance genuinely has none, so it says so.
     *
     *   RANGE — a month or a year, the reader's choice
     *   YEAR  — a year and only a year. Mandatory Leave is a calendar-year
     *           obligation; "March's mandatory leave" is not a thing
     *   NONE  — a snapshot, true as of now. A balance, or a queue
     */
    public const SCOPE_RANGE = 'range';
    public const SCOPE_YEAR = 'year';
    public const SCOPE_NONE = 'none';

    public const PERIOD_MONTHLY = 'monthly';
    public const PERIOD_ANNUAL = 'annual';

    public const PERIODS = [
        self::PERIOD_MONTHLY => 'Month',
        self::PERIOD_ANNUAL => 'Year',
    ];

    /** How much period control a report's card should offer. */
    public static function scopeOf(string $report): string
    {
        return self::CATALOGUE[$report]['scope'] ?? self::SCOPE_RANGE;
    }

    public const GROUPS = [
        'security' => 'Security',
        'leave' => 'Leave',
        // Scoped to the office the reader heads. Kept apart from 'leave' so
        // that "every application in the LGU" and "every application in my
        // office" are never two rows of the same list.
        'department' => 'My office',
    ];

    /**
     * The office this person heads, if any.
     *
     * One definition, used to decide whether the department reports are
     * offered and to scope them once they are -- so the two can never disagree
     * about which office somebody runs.
     */
    public static function officeHeadedBy(User $user): ?Department
    {
        return Department::where('head_user_id', $user->id)->first();
    }

    /** Whether a report is scoped to the reader's own office. */
    public static function isDepartmentScoped(string $report): bool
    {
        return (self::CATALOGUE[$report]['group'] ?? null) === 'department';
    }

    /** The permission a report requires, or null if there is no such report. */
    public static function permissionFor(string $report): ?string
    {
        return self::CATALOGUE[$report]['permission'] ?? null;
    }

    /**
     * The catalogue a user may actually run, grouped for the page.
     *
     * @return array<string,array<string,array<string,string>>> group slug => [key => entry]
     */
    public static function visibleTo(User $user): array
    {
        // Seeded from GROUPS so the page follows the declared order rather
        // than whichever group happened to have the first visible report.
        $groups = array_fill_keys(array_keys(self::GROUPS), []);

        // Heading an office is a fact about the record, not a permission. A
        // head who is not named on any department would otherwise be offered
        // three reports that then refuse -- the same rule their dashboard pane
        // follows: the role gets the queue, the department gets the office.
        $office = self::officeHeadedBy($user);

        foreach (self::CATALOGUE as $key => $report) {
            if (! $user->hasPermission($report['permission'])) {
                continue;
            }
            if ($report['group'] === 'department' && $office === null) {
                continue;
            }
            $groups[$report['group']][$key] = $report;
        }

        return array_filter($groups);
    }

    /** @return array{key:string,title:string,columns:array,rows:array,generated_at:string,filters:array} */
    public function build(string $report, array $filters = []): array
    {
        [$columns, $rows] = match ($report) {
            'employee-leave' => $this->employeeLeave($filters),
            'department' => $this->department($filters),
            'leave-type-summary' => $this->leaveTypeSummary($filters),
            'pending' => $this->pending($filters),
            'mandatory-leave' => $this->mandatoryLeave($filters),
            'leave-balance' => $this->leaveBalance($filters),
            // The department is already in $filters, put there by the
            // controller from the office this reader heads.
            'my-office-leave' => $this->employeeLeave($filters),
            'my-office-pending' => $this->pending($filters),
            'my-office-balances' => $this->leaveBalance($filters),
            'intrusion' => $this->intrusion($filters),
            'audit' => $this->audit($filters),
            'blocked-login' => $this->blockedLogin($filters),
            'user-activity' => $this->userActivity($filters),
            default => throw new \InvalidArgumentException("Unknown report [{$report}]."),
        };

        return [
            'key' => $report,
            'title' => self::CATALOGUE[$report]['title'] ?? $report,
            'period' => $this->periodLabel($filters, self::scopeOf($report)),
            'columns' => $columns,
            'rows' => $rows,
            'generated_at' => now()->format('F d, Y H:i'),
            'filters' => $filters,
        ];
    }

    /**
     * Every report covers either one month or one year.
     *
     * A free from/to pair let somebody produce a report of "the fourteenth to
     * the twenty-second", which is not a period anybody reconciles against
     * anything — and two people asking the same question got two different
     * ranges. A month or a year is a period the office already works in, it is
     * the same period twice running, and it prints as a caption a reader can
     * check the file against.
     */
    private function dateRange(array $f): array
    {
        $year = $this->year($f);

        if (($f['period'] ?? self::PERIOD_MONTHLY) === self::PERIOD_ANNUAL) {
            $start = Carbon::create($year, 1, 1)->startOfDay();

            return [$start, $start->copy()->endOfYear()];
        }

        $start = Carbon::create($year, $this->month($f), 1)->startOfDay();

        return [$start, $start->copy()->endOfMonth()];
    }

    /**
     * A caption naming the period, so a downloaded file says what it covers.
     *
     * A snapshot says so rather than borrowing a period it does not have — a
     * balance sheet captioned "August 2026" is a claim about last August that
     * the figures underneath do not support.
     */
    public function periodLabel(array $f, string $scope = self::SCOPE_RANGE): string
    {
        $year = $this->year($f);

        return match ($scope) {
            self::SCOPE_NONE => 'As of '.now()->format('F d, Y'),
            self::SCOPE_YEAR => 'Year '.$year,
            default => ($f['period'] ?? self::PERIOD_MONTHLY) === self::PERIOD_ANNUAL
                ? 'Year '.$year
                : Carbon::create($year, $this->month($f), 1)->format('F Y'),
        };
    }

    /** Clamped, because the year arrives from a query string. */
    private function year(array $f): int
    {
        $year = (int) ($f['year'] ?? now()->year);

        return max(2000, min((int) now()->year + 1, $year ?: (int) now()->year));
    }

    private function month(array $f): int
    {
        $month = (int) ($f['month'] ?? now()->month);

        return max(1, min(12, $month ?: (int) now()->month));
    }

    private function employeeLeave(array $f): array
    {
        [$from, $to] = $this->dateRange($f);
        $rows = LeaveRequest::with('user.employeeProfile.department', 'leaveType')
            ->whereBetween('start_date', [$from, $to])
            ->when($f['department'] ?? null, fn ($q, $d) => $q->whereHas('user.employeeProfile', fn ($w) => $w->where('department_id', $d)))
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($f['type'] ?? null, fn ($q, $t) => $q->whereHas('leaveType', fn ($w) => $w->where('code', $t)))
            ->latest('start_date')->get()
            ->map(fn ($r) => [
                $r->reference_no, $r->user->name, $r->user->employeeProfile?->department?->name ?? '—',
                $r->leaveType->name, $r->start_date->format('Y-m-d'), $r->end_date->format('Y-m-d'),
                rtrim(rtrim(number_format($r->working_days, 1), '0'), '.'), ucfirst(str_replace('_', ' ', $r->status)),
            ])->all();

        return [['Reference', 'Employee', 'Department', 'Leave Type', 'Start', 'End', 'Days', 'Status'], $rows];
    }

    /**
     * Applications per office, for the period.
     *
     * It used to count every request ever filed, whatever period was chosen,
     * under a caption naming the period — so the figures and the caption
     * disagreed with nothing on the page to say which was right.
     */
    private function department(array $f): array
    {
        [$from, $to] = $this->dateRange($f);

        $rows = Department::withCount('employees')->orderBy('name')->get()->map(function ($d) use ($from, $to) {
            $requests = LeaveRequest::whereBetween('date_filed', [$from, $to])
                ->whereHas('user.employeeProfile', fn ($w) => $w->where('department_id', $d->id));

            return [
                $d->name, $d->code, $d->employees_count,
                (clone $requests)->count(),
                (clone $requests)->where('status', 'approved')->count(),
                (clone $requests)->whereIn('status', DashboardService::OPEN_STATUSES)->count(),
            ];
        })->all();

        return [['Department', 'Code', 'Employees', 'Filed', 'Approved', 'Awaiting'], $rows];
    }

    /**
     * Totals per leave type, for the period — was the "Annual Report".
     *
     * The list of individual applications is Employee Leave Report; this is the
     * shape of them, which is a different question and the reason it survived
     * the rename rather than the drop.
     */
    private function leaveTypeSummary(array $f): array
    {
        [$from, $to] = $this->dateRange($f);
        $rows = [];

        foreach (LeaveType::orderBy('name')->get() as $type) {
            $q = LeaveRequest::where('leave_type_id', $type->id)->whereBetween('date_filed', [$from, $to]);
            $rows[] = [
                $type->name.($type->active ? '' : ' (retired)'),
                (clone $q)->count(),
                (clone $q)->where('status', 'approved')->count(),
                (clone $q)->where('status', 'rejected')->count(),
                (clone $q)->whereIn('status', DashboardService::OPEN_STATUSES)->count(),
                number_format((clone $q)->where('status', 'approved')->sum('working_days'), 1),
            ];
        }

        return [['Leave Type', 'Filed', 'Approved', 'Disapproved', 'Awaiting', 'Approved Days'], $rows];
    }

    /**
     * Everything still waiting on a decision, oldest first.
     *
     * The only report whose answer is a queue rather than a record, which is
     * also why it has no period: "the applications that were pending last
     * August" is not a question anybody asks, and it is not one the data can
     * answer — a status is current, not historical.
     */
    private function pending(array $f): array
    {
        $rows = LeaveRequest::with('user.employeeProfile.department', 'leaveType')
            ->whereIn('status', DashboardService::OPEN_STATUSES)
            ->when($f['department'] ?? null, fn ($q, $d) => $q->whereHas('user.employeeProfile', fn ($w) => $w->where('department_id', $d)))
            ->orderBy('date_filed')
            ->get()
            ->map(fn ($r) => [
                $r->reference_no,
                $r->user->name,
                $r->user->employeeProfile?->department?->name ?? '—',
                $r->leaveType->name,
                $r->date_filed->format('Y-m-d'),
                (int) $r->date_filed->startOfDay()->diffInDays(now()->startOfDay()),
                $r->start_date->format('Y-m-d'),
                ucfirst(str_replace('_', ' ', $r->status)),
            ])->all();

        return [['Reference', 'Employee', 'Department', 'Leave Type', 'Filed', 'Days Waiting', 'Starts', 'Status'], $rows];
    }

    /**
     * Employees who have not taken their Mandatory (Forced) Leave this year.
     *
     * The CSC requires five days a year and they do not carry over, so an
     * employee who has filed none of theirs in November is about to lose them
     * and HR is the office accountable. Nothing printed this before.
     *
     * Year-scoped, never monthly: the obligation is a calendar year, and
     * "March's mandatory leave" is not a thing.
     */
    private function mandatoryLeave(array $f): array
    {
        $type = LeaveType::where('code', 'FL')->first();

        if ($type === null) {
            return [['Employee', 'Department', 'Entitled', 'Used', 'Outstanding'], []];
        }

        $rows = LeaveBalance::with('user.employeeProfile.department')
            ->where('leave_type_id', $type->id)
            ->when($f['department'] ?? null, fn ($q, $d) => $q->whereHas('user.employeeProfile', fn ($w) => $w->where('department_id', $d)))
            ->get()
            // Somebody the credits never accrued for is not out of compliance.
            ->filter(fn ($b) => (float) $b->earned > 0 && (float) $b->used <= 0)
            ->sortBy(fn ($b) => [$b->user->employeeProfile?->department?->name ?? 'zz', $b->user->name])
            ->map(fn ($b) => [
                $b->user->name,
                $b->user->employeeProfile?->department?->name ?? '—',
                number_format($b->earned, 2),
                number_format($b->used, 2),
                number_format($b->balance, 2),
            ])->values()->all();

        return [['Employee', 'Department', 'Entitled', 'Used', 'Outstanding'], $rows];
    }

    private function leaveBalance(array $f): array
    {
        $rows = LeaveBalance::with('user.employeeProfile.department', 'leaveType')
            ->when($f['department'] ?? null, fn ($q, $d) => $q->whereHas('user.employeeProfile', fn ($w) => $w->where('department_id', $d)))
            ->get()
            ->map(fn ($b) => [
                $b->user->name, $b->user->employeeProfile?->department?->name ?? '—',
                $b->leaveType->code, number_format($b->earned, 2), number_format($b->used, 2), number_format($b->balance, 2),
            ])->all();

        return [['Employee', 'Department', 'Leave', 'Earned', 'Used', 'Balance'], $rows];
    }

    private function intrusion(array $f): array
    {
        [$from, $to] = $this->dateRange($f);
        $rows = IntrusionLog::with('user')->whereBetween('created_at', [$from, $to])
            ->when($f['category'] ?? null, fn ($q, $c) => $q->where('category', $c))
            ->latest()->limit(5000)->get()
            ->map(fn ($l) => [
                $l->created_at->format('Y-m-d H:i:s'), $l->category, $l->severity, $l->ip,
                $l->method.' /'.$l->route, $l->user?->name ?? '—', $l->matched_rule,
            ])->all();

        return [['Timestamp', 'Category', 'Severity', 'IP', 'Target', 'User', 'Rule'], $rows];
    }

    private function audit(array $f): array
    {
        [$from, $to] = $this->dateRange($f);
        $rows = AuditLog::with('user')->whereBetween('created_at', [$from, $to])
            ->latest()->limit(5000)->get()
            ->map(fn ($l) => [
                $l->created_at->format('Y-m-d H:i:s'), $l->user?->name ?? 'system',
                $l->role_snapshot, $l->action, class_basename($l->auditable_type).' '.$l->auditable_id, $l->ip,
            ])->all();

        return [['Timestamp', 'User', 'Role', 'Action', 'Target', 'IP'], $rows];
    }

    private function blockedLogin(array $f): array
    {
        [$from, $to] = $this->dateRange($f);
        $failed = FailedLogin::whereBetween('occurred_at', [$from, $to])->latest('occurred_at')->limit(5000)->get()
            ->map(fn ($l) => [$l->occurred_at->format('Y-m-d H:i:s'), $l->identifier, $l->ip, $l->reason, 'failed_login'])->all();
        $blocked = BlockedIp::latest()->get()
            ->map(fn ($b) => [$b->created_at->format('Y-m-d H:i:s'), $b->ip, $b->ip, $b->reason, 'blocked_ip('.$b->source.')'])->all();

        return [['Timestamp', 'Identifier/IP', 'IP', 'Reason', 'Type'], array_merge($failed, $blocked)];
    }

    private function userActivity(array $f): array
    {
        [$from, $to] = $this->dateRange($f);
        $rows = ActivityLog::with('user')->whereBetween('created_at', [$from, $to])
            ->when($f['user'] ?? null, fn ($q, $u) => $q->where('user_id', $u))
            ->latest()->limit(5000)->get()
            ->map(fn ($l) => [$l->created_at->format('Y-m-d H:i:s'), $l->user?->name ?? '—', $l->method, '/'.$l->path, $l->ip])->all();

        return [['Timestamp', 'User', 'Method', 'Path', 'IP'], $rows];
    }
}
