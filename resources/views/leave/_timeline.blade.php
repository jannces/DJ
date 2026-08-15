{{--
  Approval progress for ONE leave request. Extracted so the standalone timeline
  page and the form preview render the identical thing — the preview is now the
  single page an employee opens, and it must not drift from the tracking view.

  The workflow is single-step: submitted → pending → decided by ONE authorized
  officer. This never shows three sequential approval stages; it shows who
  actually acted.

  Expects: $r (LeaveRequest, with approvals.approver loaded).
--}}

@php
    $tlDecision = $r->approvals->firstWhere('action', '!=', \App\Models\Approval::ACTION_PENDING);
    $tlApproved = $r->status === \App\Models\LeaveRequest::STATUS_APPROVED;
    $tlRejected = $r->status === \App\Models\LeaveRequest::STATUS_REJECTED;
    $tlCancelled = $r->status === \App\Models\LeaveRequest::STATUS_CANCELLED;
    $tlReturned = $r->status === \App\Models\LeaveRequest::STATUS_RETURNED;
    $tlDecided = $tlApproved || $tlRejected || $tlCancelled;

    // Label the officer by the role they actually hold, without naming a step.
    $tlRole = null;
    if ($tlDecision?->approver) {
        $tlSlugs = app(\App\Services\Rbac\RbacService::class)->userRoleSlugs($tlDecision->approver);
        $tlRole = collect(['mayor' => 'Mayor', 'vice-mayor' => 'Vice Mayor', 'hr' => 'HR'])
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

    {{-- 2. Pending — complete once someone has acted. --}}
    <li class="tl-item {{ $tlDecided ? 'tl-done' : 'tl-current' }}">
        <span class="tl-mark" aria-hidden="true">
            @if ($tlDecided)<i class="bi bi-check-lg"></i>@endif
        </span>
        <div class="tl-body">
            <div class="tl-title">Pending Approval</div>
            @if ($tlDecided)
                <div class="tl-note">Reviewed by an authorized approver.</div>
            @elseif ($tlReturned)
                <div class="tl-note">Returned to you for revision — please review and resubmit.</div>
            @else
                <div class="tl-note">Waiting for an authorized approver.</div>
            @endif
        </div>
    </li>

    {{-- 3. Decision. --}}
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
                <div class="tl-note">Approved by {{ $tlRole ?? 'an authorized approver' }}.</div>
            @elseif ($tlRejected)
                <div class="tl-title">Rejected</div>
                <div class="tl-meta">{{ optional($r->decided_at)->format('F d, Y — g:i A') }}</div>
                <div class="tl-note">Rejected by {{ $tlRole ?? 'an authorized approver' }}.</div>
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
                <div class="tl-note">Will be updated when the Mayor, Vice Mayor or HR takes action.</div>
            @endif
        </div>
    </li>
</ol>
