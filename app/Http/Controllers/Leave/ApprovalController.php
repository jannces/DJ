<?php

namespace App\Http\Controllers\Leave;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Services\Leave\ApprovalWorkflowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ApprovalController extends Controller
{
    public function __construct(private readonly ApprovalWorkflowService $workflow)
    {
    }

    public function departmentQueue(Request $request): View
    {
        $deptId = $request->user()->employeeProfile?->department_id;
        $requests = LeaveRequest::with('leaveType', 'user.employeeProfile')
            ->where('status', LeaveRequest::STATUS_DEPT_REVIEW)
            ->when(! $request->user()->hasPermission('leave.requests.view-all'),
                fn ($q) => $q->whereHas('user.employeeProfile', fn ($w) => $w->where('department_id', $deptId)))
            ->latest()->paginate(15);

        return view('leave.review', ['requests' => $requests, 'queue' => 'department', 'title' => 'Department Reviews']);
    }

    public function hrQueue(Request $request): View
    {
        $requests = LeaveRequest::with('leaveType', 'user.employeeProfile')
            ->where('status', LeaveRequest::STATUS_HR_REVIEW)
            ->latest()->paginate(15);

        return view('leave.review', ['requests' => $requests, 'queue' => 'hr', 'title' => 'HR Validation']);
    }

    public function finalQueue(Request $request): View
    {
        $requests = LeaveRequest::with('leaveType', 'user.employeeProfile')
            ->where('status', LeaveRequest::STATUS_FINAL_REVIEW)
            ->latest()->paginate(15);

        return view('leave.review', ['requests' => $requests, 'queue' => 'final', 'title' => 'Final Approval']);
    }

    public function act(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:approved,rejected,returned'],
            'comments' => ['nullable', 'string', 'max:1000'],
            'days_with_pay' => ['nullable', 'numeric', 'min:0'],
            'days_without_pay' => ['nullable', 'numeric', 'min:0'],
            'signature' => ['nullable', 'string', 'max:150'],
        ]);

        $extra = [
            'comments' => $data['comments'] ?? null,
            'days_with_pay' => $data['days_with_pay'] ?? null,
            'days_without_pay' => $data['days_without_pay'] ?? null,
            'signature' => $data['signature'] ?? $request->user()->name,
        ];

        // HR certifies the credit snapshot automatically.
        $approval = $this->workflow->currentApproval($leaveRequest);
        if ($approval?->role_slug === 'hr') {
            $extra['certified_balances'] = $this->certification($leaveRequest);
        }

        $this->workflow->act($leaveRequest, $request->user(), $data['action'], $extra);

        return back()->with('status', 'Decision recorded.');
    }

    private function certification(LeaveRequest $r): array
    {
        $credits = app(\App\Services\Leave\LeaveCreditService::class);
        $vl = \App\Models\LeaveType::where('code', 'VL')->first();
        $sl = \App\Models\LeaveType::where('code', 'SL')->first();

        return [
            'vacation_balance' => $vl ? (float) $credits->balanceFor($r->user, $vl)->balance : 0,
            'sick_balance' => $sl ? (float) $credits->balanceFor($r->user, $sl)->balance : 0,
            'certified_at' => now()->toDateTimeString(),
        ];
    }
}
