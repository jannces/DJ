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
     * Everything awaiting HR's decision.
     *
     * One audience now. The department head step became a notification, so
     * there is no second, narrower queue and no branch here deciding which of
     * the two a visitor gets — the route admits only holders of
     * `leave.approve.final`, and every one of them sees the same list.
     *
     * STATUS_DEPT_REVIEW is still in the filter for installations that carry
     * requests filed under the old two-step flow: the migration moves them to
     * pending, but a request created by a queued job mid-deploy should not
     * become invisible because of when it happened to be written.
     */
    public function queue(Request $request): View
    {
        $requests = LeaveRequest::with('leaveType', 'user.employeeProfile.department')
            ->whereIn('status', [
                LeaveRequest::STATUS_PENDING,
                LeaveRequest::STATUS_DEPT_REVIEW,
                LeaveRequest::STATUS_RETURNED,
            ])
            ->latest()
            ->paginate(config('lists.per_page'));

        return view('leave.review', [
            'requests' => $requests,
            'title' => 'Leave Approvals',
            'decides' => true,
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

        // The officer deciding is the officer certifying — one step, one
        // person, and the credit balances are snapshotted onto the decision so
        // the printed form states what was certified rather than what the
        // ledger happens to say when somebody reprints it.
        $extra['certified_balances'] = $this->certification($leaveRequest);

        $this->workflow->act($leaveRequest, $request->user(), $data['action'], $extra);

        return back()->with('status', 'Decision recorded.');
    }

    /**
     * Box 7.B: the head of the applicant's office recommends.
     *
     * The route already carries the permission. This checks the SCOPE, which
     * the permission cannot: "may review a department" does not say which one,
     * and the answer comes from the department record naming its head, never
     * from anything in the request. Without it a head could recommend on
     * anybody in the LGU by posting the right reference number.
     */
    public function recommend(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        abort_unless($this->workflow->canRecommend($request->user(), $leaveRequest), 403);

        // Nothing to recommend on once it is decided: HR has already signed
        // 7.C or 7.D, and a recommendation added afterwards would be advice
        // about a question that is closed.
        abort_if($leaveRequest->isFinal(), 422,
            'This application has already been decided.');

        $data = $request->validate([
            'recommendation' => ['required', 'in:recommended,not_recommended'],
            // Required only when they are NOT recommending it: the printed
            // form rules a line for "For disapproval due to" and leaves the
            // approval box bare, because a refusal is the one that needs a
            // reason on it.
            'reason' => ['nullable', 'string', 'max:500', 'required_if:recommendation,not_recommended'],
        ], [], ['reason' => 'reason']);

        $favourable = $data['recommendation'] === 'recommended';

        $this->workflow->recommend(
            $leaveRequest,
            $request->user(),
            $favourable,
            $favourable ? ($data['reason'] ?? null) : $data['reason'],
        );

        return back()->with('status', $favourable
            ? 'Recommended for approval. HR still decides the application.'
            : 'Recorded as not recommended. HR still decides the application.');
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
