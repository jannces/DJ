<?php

namespace App\Services;

use App\Models\AuthorizedDevice;
use App\Models\Department;
use App\Models\EmployeeProfile;
use App\Models\IntrusionLog;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Builds role-scoped dashboard datasets (counters + chart series). */
class DashboardService
{
    public function forUser(User $user): array
    {
        $data = ['role' => $this->primaryRole($user), 'cards' => [], 'charts' => []];

        if ($user->hasPermission('security.dashboard') || $user->hasPermission('users.manage')) {
            $data['cards'] += [
                'employees' => EmployeeProfile::count(),
                'pending_leaves' => LeaveRequest::whereNotIn('status', ['approved', 'rejected', 'cancelled'])->count(),
                'intrusions_today' => IntrusionLog::whereDate('created_at', today())->count(),
                'devices_online' => AuthorizedDevice::active()->where('last_active_at', '>', now()->subMinutes(5))->count(),
                'devices_offline' => AuthorizedDevice::active()->where(fn ($q) => $q->whereNull('last_active_at')->orWhere('last_active_at', '<=', now()->subMinutes(5)))->count(),
            ];
        }

        if ($user->hasPermission('leave.requests.view-all') || $user->hasPermission('leave.certify.hr')) {
            $data['cards'] += [
                'total_requests' => LeaveRequest::count(),
                'approved' => LeaveRequest::where('status', 'approved')->count(),
                'departments' => Department::count(),
            ];
        }

        // Employee self-service cards
        if ($user->hasPermission('leave.view-own')) {
            $data['cards'] += [
                'my_pending' => LeaveRequest::where('user_id', $user->id)->whereNotIn('status', ['approved', 'rejected', 'cancelled'])->count(),
                'my_approved' => LeaveRequest::where('user_id', $user->id)->where('status', 'approved')->count(),
            ];
            $data['my_balances'] = LeaveBalance::with('leaveType')->where('user_id', $user->id)->get();
        }

        // Chart series (only computed for roles that render them).
        if ($user->hasPermission('leave.requests.view-all')) {
            $data['chartsLeavesMonth'] = $this->leavesByMonth();
            $data['chartsLeavesType'] = $this->leavesByType();
        }
        if ($user->hasPermission('security.dashboard')) {
            $data['chartsIntrusions'] = $this->intrusionsByDay();
        }

        return $data;
    }

    /**
     * Read-only view data for the HR user's *personal* Overview page. Every
     * value is a plain read of rows the system already writes — no counting
     * rule, balance calculation or status logic is defined here.
     */
    public function personalOverview(User $user): array
    {
        $own = LeaveRequest::where('user_id', $user->id);

        return [
            'balances' => LeaveBalance::with('leaveType')->where('user_id', $user->id)->get(),
            'counts' => [
                'pending' => (clone $own)->whereNotIn('status', ['approved', 'rejected', 'cancelled'])->count(),
                'approved' => (clone $own)->where('status', 'approved')->count(),
                'rejected' => (clone $own)->where('status', 'rejected')->count(),
            ],
            'upcoming' => (clone $own)->with('leaveType')
                ->where('status', 'approved')
                ->whereDate('end_date', '>=', today())
                ->orderBy('start_date')->limit(3)->get(),
            'recent' => (clone $own)->with('leaveType')->latest('updated_at')->limit(5)->get(),
        ];
    }

    /**
     * Read-only view data for the organisation-wide HR dashboard. Counters and
     * series come from the same queries the dashboard already used.
     */
    public function hrOverview(): array
    {
        return [
            'kpis' => [
                'employees' => EmployeeProfile::count(),
                'pending' => LeaveRequest::whereNotIn('status', ['approved', 'rejected', 'cancelled'])->count(),
                'awaiting_hr' => LeaveRequest::where('status', LeaveRequest::STATUS_HR_REVIEW)->count(),
                'approved_this_month' => LeaveRequest::where('status', 'approved')
                    ->whereBetween('decided_at', [now()->startOfMonth(), now()->endOfMonth()])->count(),
                'on_leave_today' => LeaveRequest::where('status', 'approved')
                    ->whereDate('start_date', '<=', today())
                    ->whereDate('end_date', '>=', today())->count(),
                'departments' => Department::count(),
            ],
            'trend' => $this->leavesByMonth(),
            'byType' => $this->leavesByType(),
            'byDepartment' => $this->employeesByDepartment(),
            'recent' => LeaveRequest::with('leaveType', 'user.employeeProfile.department')
                ->latest()->limit(8)->get(),
        ];
    }

    /** Headcount per department, for the HR dashboard bar chart. */
    public function employeesByDepartment(): array
    {
        $rows = Department::withCount('employees')->orderByDesc('employees_count')->limit(8)->get();

        return [
            'labels' => $rows->pluck('name')->all(),
            'data' => $rows->pluck('employees_count')->all(),
        ];
    }

    public function primaryRole(User $user): string
    {
        return app(\App\Services\Rbac\RbacService::class)->userRoleSlugs($user)->first() ?? 'employee';
    }

    /** Leave requests grouped by month for the last 6 months (Chart.js). */
    public function leavesByMonth(): array
    {
        $rows = LeaveRequest::query()
            ->where('created_at', '>=', now()->subMonths(5)->startOfMonth())
            ->get()
            ->groupBy(fn ($r) => $r->created_at->format('Y-m'));

        $labels = [];
        $data = [];
        for ($i = 5; $i >= 0; $i--) {
            $key = now()->subMonths($i)->format('Y-m');
            $labels[] = now()->subMonths($i)->format('M Y');
            $data[] = $rows->get($key)?->count() ?? 0;
        }

        return ['labels' => $labels, 'data' => $data];
    }

    public function leavesByType(): array
    {
        $rows = LeaveRequest::select('leave_type_id', DB::raw('count(*) as total'))
            ->with('leaveType:id,code')
            ->groupBy('leave_type_id')->get();

        return [
            'labels' => $rows->map(fn ($r) => $r->leaveType?->code ?? '—')->all(),
            'data' => $rows->pluck('total')->all(),
        ];
    }

    public function intrusionsByDay(): array
    {
        $labels = [];
        $data = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = now()->subDays($i);
            $labels[] = $day->format('D');
            $data[] = IntrusionLog::whereDate('created_at', $day->toDateString())->count();
        }

        return ['labels' => $labels, 'data' => $data];
    }
}
