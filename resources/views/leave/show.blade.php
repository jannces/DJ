@extends('layouts.app')
@section('title', 'Leave '.$leaveRequest->reference_no)
@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h4 mb-0">{{ $leaveRequest->reference_no }}</h1>
        <div class="text-muted small">{{ $leaveRequest->leaveType->name }} · filed {{ $leaveRequest->date_filed->format('M d, Y') }}</div>
    </div>
    <div>
        <a href="{{ route('leave.form6', $leaveRequest) }}" class="btn btn-outline-secondary btn-sm" target="_blank"><i class="bi bi-printer me-1"></i>CSC Form 6 (PDF)</a>
        @if ($leaveRequest->user_id === auth()->id() && $leaveRequest->isCancellable())
            <form method="POST" action="{{ route('leave.cancel', $leaveRequest) }}" class="d-inline" data-confirm="Cancel this request?">
                @csrf<button class="btn btn-outline-danger btn-sm">Cancel</button>
            </form>
        @endif
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between">
                <span class="fw-semibold">Application details</span>
                @include('leave._status_badge', ['status' => $leaveRequest->status])
            </div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">Applicant</dt><dd class="col-sm-8">{{ $leaveRequest->user->name }}</dd>
                    <dt class="col-sm-4">Office / Department</dt><dd class="col-sm-8">{{ $leaveRequest->office_snapshot ?? '—' }}</dd>
                    <dt class="col-sm-4">Position</dt><dd class="col-sm-8">{{ $leaveRequest->position_snapshot ?? '—' }}</dd>
                    <dt class="col-sm-4">Inclusive dates</dt><dd class="col-sm-8">{{ $leaveRequest->start_date->format('M d, Y') }} – {{ $leaveRequest->end_date->format('M d, Y') }}</dd>
                    <dt class="col-sm-4">Working days</dt><dd class="col-sm-8">{{ rtrim(rtrim(number_format($leaveRequest->working_days,1),'0'),'.') }}</dd>
                    @if ($leaveRequest->details)
                        @foreach ($leaveRequest->details as $k => $v)
                            @if ($v)<dt class="col-sm-4 text-capitalize">{{ str_replace('_',' ',$k) }}</dt><dd class="col-sm-8">{{ is_array($v) ? implode(', ', $v) : $v }}</dd>@endif
                        @endforeach
                    @endif
                    @if ($leaveRequest->purpose)<dt class="col-sm-4">Purpose</dt><dd class="col-sm-8">{{ $leaveRequest->purpose }}</dd>@endif
                    <dt class="col-sm-4">Commutation</dt><dd class="col-sm-8">{{ $leaveRequest->commutation ? 'Requested' : 'Not requested' }}</dd>
                    @if ($leaveRequest->is_late_filing)<dt class="col-sm-4">Late filing reason</dt><dd class="col-sm-8">{{ $leaveRequest->late_filing_reason }}</dd>@endif
                    @if ($leaveRequest->status==='approved')
                        <dt class="col-sm-4">Days with pay</dt><dd class="col-sm-8">{{ $leaveRequest->days_with_pay }}</dd>
                        <dt class="col-sm-4">Days without pay</dt><dd class="col-sm-8">{{ $leaveRequest->days_without_pay }}</dd>
                    @endif
                    @if ($leaveRequest->disapproval_reason)<dt class="col-sm-4 text-danger">Disapproval reason</dt><dd class="col-sm-8">{{ $leaveRequest->disapproval_reason }}</dd>@endif
                </dl>
                @if ($leaveRequest->filing_warnings)
                    <div class="alert alert-warning small mt-2 mb-0">
                        @foreach ($leaveRequest->filing_warnings as $w)<div>⚠ {{ $w }}</div>@endforeach
                    </div>
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-header fw-semibold">Supporting documents</div>
            <ul class="list-group list-group-flush">
                @forelse ($leaveRequest->documents as $doc)
                    <li class="list-group-item d-flex justify-content-between">
                        <span><i class="bi bi-paperclip me-1"></i>{{ str_replace('_',' ',$doc->type) }} — {{ $doc->original_name }}</span>
                        <a href="{{ route('leave.documents.download', $doc) }}" class="btn btn-sm btn-link">Download</a>
                    </li>
                @empty
                    <li class="list-group-item text-muted small">No documents uploaded.</li>
                @endforelse
            </ul>
            @if ($leaveRequest->user_id === auth()->id() && !$leaveRequest->isFinal())
                <div class="card-body">
                    <form method="POST" action="{{ route('leave.documents.store', $leaveRequest) }}" enctype="multipart/form-data" class="row g-2" data-no-loader>
                        @csrf
                        <div class="col-md-5"><input name="type" class="form-control form-control-sm" placeholder="Document type" required></div>
                        <div class="col-md-5"><input type="file" name="document" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png" required></div>
                        <div class="col-md-2"><button class="btn btn-sm btn-lgu w-100">Upload</button></div>
                    </form>
                </div>
            @endif
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-header fw-semibold">Approval timeline</div>
            <div class="card-body">
                <ul class="list-unstyled mb-0">
                    <li class="mb-3">
                        <i class="bi bi-check-circle-fill text-success"></i> <strong>Submitted</strong>
                        <div class="text-muted small">{{ $leaveRequest->created_at->format('M d, Y H:i') }}</div>
                    </li>
                    @foreach ($leaveRequest->approvals as $a)
                        @php
                            $done = $a->action !== 'pending';
                            $roleLabel = ['department_head'=>'Department Head','hr'=>'HR Officer','mayor'=>'Municipal Mayor'][$a->role_slug] ?? $a->role_slug;
                            $icon = match($a->action){'approved','certified'=>'bi-check-circle-fill text-success','rejected'=>'bi-x-circle-fill text-danger','returned'=>'bi-arrow-counterclockwise text-warning',default=>'bi-circle text-muted'};
                        @endphp
                        <li class="mb-3">
                            <i class="bi {{ $icon }}"></i> <strong>{{ $roleLabel }}</strong>
                            <div class="text-muted small">
                                @if ($done)
                                    {{ ucfirst($a->action) }} by {{ $a->approver?->name }} · {{ $a->acted_at?->format('M d, H:i') }}
                                    @if ($a->comments)<div class="fst-italic">“{{ $a->comments }}”</div>@endif
                                    @if ($a->certified_balances)<div>VL: {{ $a->certified_balances['vacation_balance'] ?? '—' }} · SL: {{ $a->certified_balances['sick_balance'] ?? '—' }}</div>@endif
                                @else
                                    Pending
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</div>
@endsection
