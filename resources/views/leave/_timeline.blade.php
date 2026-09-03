{{--
  Approval progress for ONE leave request. Extracted so the standalone timeline
  page and the form preview render the identical thing — the preview is now the
  single page an employee opens, and it must not drift from the tracking view.

  The workflow is single-step: submitted → the applicant's department head is
  notified → HR decides. The middle line is not a stage the application waits
  at; it is a record that somebody was told, which is why it is never "current"
  and never holds the timeline open.

  Expects: $r (LeaveRequest, with approvals.approver loaded).
--}}

@php
    // Scoped to step 1. Unscoped, this picked up the department head's
    // notification row -- which is not pending, and is not a decision -- and
    // printed the head as the officer who decided the application.
    $tlDecision = $r->approvals->first(fn ($a) => $a->step_no === 1
        && $a->action !== \App\Models\Approval::ACTION_PENDING);
    $tlNotified = $r->approvals->firstWhere('role_slug', \App\Services\Leave\ApprovalWorkflowService::STEP_DEPARTMENT);
    $tlApproved = $r->status === \App\Models\LeaveRequest::STATUS_APPROVED;
    $tlRejected = $r->status === \App\Models\LeaveRequest::STATUS_REJECTED;
    $tlCancelled = $r->status === \App\Models\LeaveRequest::STATUS_CANCELLED;
    $tlReturned = $r->status === \App\Models\LeaveRequest::STATUS_RETURNED;
    $tlDecided = $tlApproved || $tlRejected || $tlCancelled;

    // Label the officer by the role they actually hold, without naming a step.
    $tlRole = null;
    if ($tlDecision?->approver) {
        $tlSlugs = app(\App\Services\Rbac\RbacService::class)->userRoleSlugs($tlDecision->approver);
        $tlRole = collect(['hr' => 'HR', 'mayor' => 'Mayor'])
            ->first(fn ($label, $slug) => $tlSlugs->contains($slug));
    }
@endphp

<ol class="tl">
    {{-- 1. Submitted — always complete. --}}
    <li class="tl-item tl-done">
        <span class="tl-mark" aria-hidden="true"><i class="bi bi-check-lg"></i></span>
        <div class="tl-body">
            <div class="tl-title">Application Submitted</div>
            <div class="tl-meta">{{ $r->created_at->format('F d, Y — g:i A') }}</div>
            <div class="tl-note">Leave application submitted successfully.</div>
        </div>
    </li>

    {{-- 2. The head was told. Always complete, or absent — never current: an
           application does not wait here, and a step that cannot be waited at
           should not be drawn as one. Absent when the office has no head, or
           when the applicant heads it themselves. --}}
    @if ($tlNotified)
        <li class="tl-item tl-done">
            <span class="tl-mark" aria-hidden="true"><i class="bi bi-check-lg"></i></span>
            <div class="tl-body">
                <div class="tl-title">Department Head Notified</div>
                <div class="tl-meta">{{ optional($tlNotified->acted_at)->format('F d, Y — g:i A') }}</div>
                <div class="tl-note">
                    {{ $tlNotified->signature ?? $tlNotified->approver?->name ?? 'Your department head' }}
                    was informed that you will be away. No approval is needed from them.
                </div>
            </div>
        </li>
    @endif

    {{-- 3. Pending — complete once HR has acted. --}}
    <li class="tl-item {{ $tlDecided ? 'tl-done' : 'tl-current' }}">
        <span class="tl-mark" aria-hidden="true">
            @if ($tlDecided)<i class="bi bi-check-lg"></i>@endif
        </span>
        <div class="tl-body">
            <div class="tl-title">Pending Approval</div>
            @if ($tlDecided)
                <div class="tl-note">Reviewed by HR.</div>
            @elseif ($tlReturned)
                <div class="tl-note">Returned to you for revision — please review and resubmit.</div>
            @else
                <div class="tl-note">Waiting for HR to validate and decide.</div>
            @endif
        </div>
    </li>

    {{-- 4. Decision. --}}
    <li class="tl-item {{ $tlApproved ? 'tl-done' : ($tlRejected || $tlCancelled ? 'tl-bad' : '') }}">
        <span class="tl-mark" aria-hidden="true">
            @if ($tlApproved)<i class="bi bi-check-lg"></i>
            @elseif ($tlRejected || $tlCancelled)<i class="bi bi-x-lg"></i>
            @endif
        </span>
        <div class="tl-body">
            @if ($tlApproved)
                <div class="tl-title">Approved</div>
                <div class="tl-meta">{{ optional($r->decided_at)->format('F d, Y — g:i A') }}</div>
                <div class="tl-note">Approved by {{ $tlRole ?? 'HR' }}.</div>
            @elseif ($tlRejected)
                <div class="tl-title">Rejected</div>
                <div class="tl-meta">{{ optional($r->decided_at)->format('F d, Y — g:i A') }}</div>
                <div class="tl-note">Rejected by {{ $tlRole ?? 'HR' }}.</div>
                @if ($r->disapproval_reason)
                    <div class="tl-reason">
                        <strong>Reason:</strong> {{ $r->disapproval_reason }}
                    </div>
                @endif
            @elseif ($tlCancelled)
                <div class="tl-title">Cancelled</div>
                <div class="tl-meta">{{ optional($r->decided_at)->format('F d, Y — g:i A') }}</div>
                <div class="tl-note">You cancelled this application.</div>
            @else
                <div class="tl-title text-muted">Approved / Rejected</div>
                <div class="tl-note">Will be updated when HR takes action.</div>
            @endif
        </div>
    </li>
</ol>
