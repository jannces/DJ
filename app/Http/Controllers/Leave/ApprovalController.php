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

    /**
     * One page, showing what the signed-in officer may actually act on.
     *
     * Mayor and HR see every application awaiting a decision — including ones
     * still at department review, which they may decide past so that an absent
     * head never strands somebody's leave.
     *
     * A department head sees only their own office's applications, only while
     * those are at department review. Scoped by the head named on the office
     * rather than by "works in that department", so two people in one office
     * cannot both act.
     */
    public function queue(Request $request): View
    {
        $user = $request->user();
        $decides = $user->hasPermission(ApprovalWorkflowService::STEP_PERMISSION);

        $query = LeaveRequest::with('leaveType', 'user.employeeProfile.department');

        if ($decides) {
            $query->whereIn('status', [
                LeaveRequest::STATUS_PENDING,
                LeaveRequest::STATUS_DEPT_REVIEW,
                LeaveRequest::STATUS_RETURNED,
            ]);
        } else {
            $query->where('status', LeaveRequest::STATUS_DEPT_REVIEW)
                ->whereHas('user.employeeProfile.department',
                    fn ($q) => $q->where('head_user_id', $user->id));
        }

        return view('leave.review', [
            'requests' => $query->latest()->paginate(config('lists.per_page')),
            'title' => $decides ? 'Leave Approvals' : 'Department Review',
            'decides' => $decides,
        ]);
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

        // Only a deciding officer certifies credits. A department head
        // recommends on the strength of the request and the office's coverage;
        // the credit ledger is HR's competence and is not their business.
        if ($request->user()->hasPermission(ApprovalWorkflowService::STEP_PERMISSION)) {
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
