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
     * The single approval queue. Mayor, Vice Mayor and HR all see the same
     * pending applications — whichever of them acts first decides it.
     */
    public function queue(Request $request): View
    {
        $requests = LeaveRequest::with('leaveType', 'user.employeeProfile.department')
            ->whereIn('status', [LeaveRequest::STATUS_PENDING, LeaveRequest::STATUS_RETURNED])
            ->latest()->paginate(15);

        return view('leave.review', [
            'requests' => $requests,
            'title' => 'Leave Approvals',
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

        // The deciding officer certifies the credit snapshot at the moment of
        // the decision, whichever of the three authorized roles they hold.
        $extra['certified_balances'] = $this->certification($leaveRequest);

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
