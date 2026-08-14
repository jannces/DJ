@extends('layouts.app')
@section('title', 'Approval Timeline')
@section('content')

{{--
  EMPLOYEE-ONLY tracking view. The approval queue used by Mayor, Vice Mayor and
  HR deliberately has no timeline — this exists so an applicant can follow their
  own request. Ownership is enforced in the controller (authorizeView), not by
  hiding the link.

  The workflow is single-step: submitted → pending → decided by ONE authorized
  officer. The timeline therefore never shows three sequential approval stages;
  it shows who actually acted.
--}}

@php
    $decision = $r->approvals->firstWhere('action', '!=', \App\Models\Approval::ACTION_PENDING);
    $isApproved = $r->status === \App\Models\LeaveRequest::STATUS_APPROVED;
    $isRejected = $r->status === \App\Models\LeaveRequest::STATUS_REJECTED;
    $isCancelled = $r->status === \App\Models\LeaveRequest::STATUS_CANCELLED;
    $isReturned = $r->status === \App\Models\LeaveRequest::STATUS_RETURNED;
    $decided = $isApproved || $isRejected || $isCancelled;

    // Label the officer by the role they actually hold, without naming a step.
    $approverRole = null;
    if ($decision?->approver) {
        $slugs = app(\App\Services\Rbac\RbacService::class)->userRoleSlugs($decision->approver);
        $approverRole = collect(['mayor' => 'Mayor', 'vice-mayor' => 'Vice Mayor', 'hr' => 'HR'])
            ->first(fn ($label, $slug) => $slugs->contains($slug));
    }
@endphp

<div class="page-head">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h1>Approval Timeline</h1>
            <div class="sub">{{ $r->reference_no }} &middot; {{ $r->leaveType->name }}</div>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('leave.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Back to My Leave Requests
            </a>
            <a href="{{ route('leave.preview-form', $r) }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-file-earmark-text me-1"></i>View Form
            </a>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header fw-semibold">Progress</div>
            <div class="card-body">
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
                    <li class="tl-item {{ $decided ? 'tl-done' : 'tl-current' }}">
                        <span class="tl-mark" aria-hidden="true">
                            @if ($decided)<i class="bi bi-check-lg"></i>@endif
                        </span>
                        <div class="tl-body">
                            <div class="tl-title">Pending Approval</div>
                            @if ($decided)
                                <div class="tl-note">Reviewed by an authorized approver.</div>
                            @elseif ($isReturned)
                                <div class="tl-note">Returned to you for revision — please review and resubmit.</div>
                            @else
                                <div class="tl-note">Waiting for an authorized approver.</div>
                            @endif
                        </div>
                    </li>

                    {{-- 3. Decision. --}}
                    <li class="tl-item {{ $isApproved ? 'tl-done' : ($isRejected || $isCancelled ? 'tl-bad' : '') }}">
                        <span class="tl-mark" aria-hidden="true">
                            @if ($isApproved)<i class="bi bi-check-lg"></i>
                            @elseif ($isRejected || $isCancelled)<i class="bi bi-x-lg"></i>
                            @endif
                        </span>
                        <div class="tl-body">
                            @if ($isApproved)
                                <div class="tl-title">Approved</div>
                                <div class="tl-meta">{{ optional($r->decided_at)->format('F d, Y — g:i A') }}</div>
                                <div class="tl-note">Approved by {{ $approverRole ?? 'an authorized approver' }}.</div>
                            @elseif ($isRejected)
                                <div class="tl-title">Rejected</div>
                                <div class="tl-meta">{{ optional($r->decided_at)->format('F d, Y — g:i A') }}</div>
                                <div class="tl-note">Rejected by {{ $approverRole ?? 'an authorized approver' }}.</div>
                                @if ($r->disapproval_reason)
                                    <div class="tl-reason">
                                        <strong>Reason:</strong> {{ $r->disapproval_reason }}
                                    </div>
                                @endif
                            @elseif ($isCancelled)
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
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card">
            <div class="card-header fw-semibold">Application summary</div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-5 text-muted">Reference</dt><dd class="col-7">{{ $r->reference_no }}</dd>
                    <dt class="col-5 text-muted">Leave type</dt><dd class="col-7">{{ $r->leaveType->name }}</dd>
                    <dt class="col-5 text-muted">Inclusive dates</dt>
                    <dd class="col-7">{{ $r->start_date->format('M d') }} – {{ $r->end_date->format('M d, Y') }}</dd>
                    <dt class="col-5 text-muted">Working days</dt>
                    <dd class="col-7">{{ rtrim(rtrim(number_format($r->working_days, 1), '0'), '.') }}</dd>
                    <dt class="col-5 text-muted">Commutation</dt>
                    <dd class="col-7">{{ $r->commutation ? 'Requested' : 'Not requested' }}</dd>
                    <dt class="col-5 text-muted">Status</dt>
                    <dd class="col-7">@include('leave._status_badge', ['status' => $r->status])</dd>
                    @if ($isApproved)
                        <dt class="col-5 text-muted">Days with pay</dt>
                        <dd class="col-7">{{ rtrim(rtrim(number_format((float) $r->days_with_pay, 1), '0'), '.') }}</dd>
                    @endif
                </dl>
            </div>
        </div>
    </div>
</div>

@endsection
