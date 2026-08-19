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
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Produces the nine report datasets. Each returns a uniform structure
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
        'employee-leave' => ['title' => 'Employee Leave Report', 'permission' => 'leave.requests.view-all', 'group' => 'leave'],
        'department' => ['title' => 'Department Report', 'permission' => 'leave.requests.view-all', 'group' => 'leave'],
        'monthly' => ['title' => 'Monthly Report', 'permission' => 'leave.requests.view-all', 'group' => 'leave'],
        'annual' => ['title' => 'Annual Report', 'permission' => 'leave.requests.view-all', 'group' => 'leave'],
        'leave-balance' => ['title' => 'Leave Balance Report', 'permission' => 'leave.requests.view-all', 'group' => 'leave'],
        'intrusion' => ['title' => 'Intrusion Report', 'permission' => 'reports.security', 'group' => 'security'],
        'audit' => ['title' => 'Audit Report', 'permission' => 'reports.security', 'group' => 'security'],
        'blocked-login' => ['title' => 'Blocked Login Report', 'permission' => 'reports.security', 'group' => 'security'],
        'user-activity' => ['title' => 'User Activity Report', 'permission' => 'reports.security', 'group' => 'security'],
    ];

    public const PERIOD_MONTHLY = 'monthly';
    public const PERIOD_ANNUAL = 'annual';

    public const PERIODS = [
        self::PERIOD_MONTHLY => 'Monthly',
        self::PERIOD_ANNUAL => 'Yearly',
    ];

    public const GROUPS = [
        'security' => 'Security',
        'leave' => 'Leave',
    ];

    /** The permission a report requires, or null if there is no such report. */
    public static function permissionFor(string $report): ?string
    {
        return self::CATALOGUE[$report]['permission'] ?? null;
    }

    /**
     * The catalogue a user may actually run, grouped for the page.
     *
     * @return array<string,array<string,string>> group slug => [key => title]
     */
    public static function visibleTo(User $user): array
    {
        // Seeded from GROUPS so the page follows the declared order rather
        // than whichever group happened to have the first visible report.
        $groups = array_fill_keys(array_keys(self::GROUPS), []);

        foreach (self::CATALOGUE as $key => $report) {
            if ($user->hasPermission($report['permission'])) {
                $groups[$report['group']][$key] = $report['title'];
            }
        }

        return array_filter($groups);
    }

    /** @return array{key:string,title:string,columns:array,rows:array,generated_at:string,filters:array} */
    public function build(string $report, array $filters = []): array
    {
        [$columns, $rows] = match ($report) {
            'employee-leave' => $this->employeeLeave($filters),
            'department' => $this->department($filters),
            'monthly' => $this->monthly($filters),
            'annual' => $this->annual($filters),
            'leave-balance' => $this->leaveBalance($filters),
            'intrusion' => $this->intrusion($filters),
            'audit' => $this->audit($filters),
            'blocked-login' => $this->blockedLogin($filters),
            'user-activity' => $this->userActivity($filters),
            default => throw new \InvalidArgumentException("Unknown report [{$report}]."),
        };

        return [
            'key' => $report,
            'title' => self::CATALOGUE[$report]['title'] ?? $report,
            'period' => $this->periodLabel($filters),
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

    /** A caption naming the period, so a downloaded file says what it covers. */
    public function periodLabel(array $f): string
    {
        $year = $this->year($f);

        return ($f['period'] ?? self::PERIOD_MONTHLY) === self::PERIOD_ANNUAL
            ? 'Year '.$year
            : Carbon::create($year, $this->month($f), 1)->format('F Y');
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

    private function department(array $f): array
    {
        $rows = Department::withCount('employees')->get()->map(function ($d) {
            $requests = LeaveRequest::whereHas('user.employeeProfile', fn ($w) => $w->where('department_id', $d->id));

            return [
                $d->name, $d->code, $d->employees_count,
                (clone $requests)->count(),
                (clone $requests)->where('status', 'approved')->count(),
                (clone $requests)->whereNotIn('status', ['approved', 'rejected', 'cancelled'])->count(),
            ];
        })->all();

        return [['Department', 'Code', 'Employees', 'Total Requests', 'Approved', 'Pending'], $rows];
    }

    private function monthly(array $f): array
    {
        $start = Carbon::create($this->year($f), $this->month($f), 1)->startOfMonth();
        $end = (clone $start)->endOfMonth();

        $rows = LeaveRequest::with('user', 'leaveType')
            ->whereBetween('start_date', [$start, $end])->get()
            ->map(fn ($r) => [
                $r->reference_no, $r->user->name, $r->leaveType->name,
                $r->start_date->format('Y-m-d'), rtrim(rtrim(number_format($r->working_days, 1), '0'), '.'),
                ucfirst(str_replace('_', ' ', $r->status)),
            ])->all();

        return [['Reference', 'Employee', 'Type', 'Start', 'Days', 'Status'], $rows];
    }

    private function annual(array $f): array
    {
        $year = $this->year($f);
        $rows = [];
        foreach (\App\Models\LeaveType::orderBy('name')->get() as $type) {
            $q = LeaveRequest::where('leave_type_id', $type->id)->whereYear('start_date', $year);
            $rows[] = [
                $type->name, (clone $q)->count(),
                (clone $q)->where('status', 'approved')->count(),
                (clone $q)->where('status', 'rejected')->count(),
                number_format((clone $q)->where('status', 'approved')->sum('working_days'), 1),
            ];
        }

        return [['Leave Type', 'Filed', 'Approved', 'Disapproved', 'Approved Days'], $rows];
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
