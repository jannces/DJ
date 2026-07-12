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
 */
class ReportService
{
    public const REPORTS = [
        'employee-leave' => 'Employee Leave Report',
        'department' => 'Department Report',
        'monthly' => 'Monthly Report',
        'annual' => 'Annual Report',
        'leave-balance' => 'Leave Balance Report',
        'intrusion' => 'Intrusion Report',
        'audit' => 'Audit Report',
        'blocked-login' => 'Blocked Login Report',
        'user-activity' => 'User Activity Report',
    ];

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
            'title' => self::REPORTS[$report] ?? $report,
            'columns' => $columns,
            'rows' => $rows,
            'generated_at' => now()->format('F d, Y H:i'),
            'filters' => $filters,
        ];
    }

    private function dateRange(array $f): array
    {
        $from = ! empty($f['from']) ? Carbon::parse($f['from'])->startOfDay() : now()->startOfYear();
        $to = ! empty($f['to']) ? Carbon::parse($f['to'])->endOfDay() : now()->endOfDay();

        return [$from, $to];
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
        $year = (int) ($f['year'] ?? now()->year);
        $month = (int) ($f['month'] ?? now()->month);
        $start = Carbon::create($year, $month, 1)->startOfMonth();
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
        $year = (int) ($f['year'] ?? now()->year);
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
