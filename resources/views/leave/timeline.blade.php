@extends('layouts.app')
@section('title', 'Approval Timeline')
@section('content')

{{--
  EMPLOYEE-ONLY tracking view, kept as a direct route (bookmarks, notification
  links). The route the employee normally follows is the form preview, which
  embeds this same progress list from leave/_timeline.blade.php — one source, so
  the two can never disagree. Ownership is enforced in the controller
  (authorizeView), not by hiding the link.
--}}

@php
    $isApproved = $r->status === \App\Models\LeaveRequest::STATUS_APPROVED;
@endphp

<x-page-head
    title="Approval Timeline"
    :sub="$r->reference_no.' · '.$r->leaveType->name"
    :back-url="route('leave.index')" back-label="My Leave Requests">
    <a href="{{ route('leave.preview-form', $r) }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-file-earmark-text me-1"></i>View Form
    </a>
</x-page-head>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header fw-semibold">Progress</div>
            <div class="card-body">
                @include('leave._timeline', ['r' => $r])
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
