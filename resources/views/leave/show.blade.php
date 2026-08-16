@extends('layouts.app')
@section('title', 'Leave '.$leaveRequest->reference_no)
@section('content')
{{--
    Request detail. Same data, same actions, same routes — regrouped into
    Employee Information / Leave Information / Approval Authority / Timeline.
--}}
<div class="page-head">
    <nav class="crumbs" aria-label="breadcrumb">
        <a href="{{ route('dashboard') }}">Overview</a><span class="sep">/</span>
        @can('leave.requests.view-all')
            <a href="{{ route('leave.all') }}">Leave Requests</a><span class="sep">/</span>
        @else
            <a href="{{ route('leave.index') }}">My Leave Requests</a><span class="sep">/</span>
        @endcan
        <span>{{ $leaveRequest->reference_no }}</span>
    </nav>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h1>{{ $leaveRequest->reference_no }}</h1>
            <div class="sub">
                {{ $leaveRequest->leaveType->name }} · filed {{ $leaveRequest->date_filed->format('M j, Y') }}
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            @include('partials.status-pill', ['status' => $leaveRequest->status])
            <a href="{{ route('leave.form6', $leaveRequest) }}" class="btn btn-outline-secondary btn-sm" target="_blank">
                <i class="bi bi-printer" aria-hidden="true"></i>CSC Form 6 (PDF)
            </a>
            @if ($leaveRequest->user_id === auth()->id() && $leaveRequest->isCancellable())
                <form method="POST" action="{{ route('leave.cancel', $leaveRequest) }}" class="d-inline" data-confirm="Cancel this request?">
                    @csrf<button class="btn btn-outline-danger btn-sm">Cancel request</button>
                </form>
            @endif
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="hr-stack">
            {{-- Employee information ------------------------------------- --}}
            <div class="card">
                <div class="card-header">Employee Information</div>
                <div class="card-body">
                    <dl class="def-grid">
                        <div><dt>Name</dt><dd>{{ $leaveRequest->user->name }}</dd></div>
                        <div><dt>Office / Department</dt><dd>{{ $leaveRequest->office_snapshot ?? '—' }}</dd></div>
                        <div><dt>Position</dt><dd>{{ $leaveRequest->position_snapshot ?? '—' }}</dd></div>
                    </dl>
                </div>
            </div>

            {{-- Leave information ---------------------------------------- --}}
            <div class="card">
                <div class="card-header">Leave Information</div>
                <div class="card-body">
                    <dl class="def-grid">
                        <div><dt>Leave Type</dt><dd>{{ $leaveRequest->leaveType->name }}</dd></div>
                        <div><dt>Classification</dt><dd>{{ $leaveRequest->leaveType->deductible ? 'Deductible' : 'Non-deductible' }}</dd></div>
                        <div><dt>Start Date</dt><dd class="cell-num">{{ $leaveRequest->start_date->format('M j, Y') }}</dd></div>
                        <div><dt>End Date</dt><dd class="cell-num">{{ $leaveRequest->end_date->format('M j, Y') }}</dd></div>
                        <div><dt>Working Days</dt><dd class="cell-num">{{ rtrim(rtrim(number_format($leaveRequest->working_days, 1), '0'), '.') }}</dd></div>
                        <div><dt>Commutation</dt><dd>{{ $leaveRequest->commutation ? 'Requested' : 'Not requested' }}</dd></div>
                        @if ($leaveRequest->status === 'approved')
                            <div><dt>Days With Pay</dt><dd class="cell-num">{{ $leaveRequest->days_with_pay }}</dd></div>
                            <div><dt>Days Without Pay</dt><dd class="cell-num">{{ $leaveRequest->days_without_pay }}</dd></div>
                        @endif
                        @if ($leaveRequest->details)
                            @foreach ($leaveRequest->details as $key => $value)
                                @if ($value)
                                    @php
                                        // Stored keys such as "within_ph" are machine values; show them as words.
                                        $readable = is_array($value)
                                            ? implode(', ', $value)
                                            : (is_string($value) && ! str_contains($value, ' ') && str_contains($value, '_')
                                                ? ucwords(str_replace('_', ' ', $value))
                                                : $value);
                                    @endphp
                                    <div>
                                        <dt>{{ ucwords(str_replace('_', ' ', $key)) }}</dt>
                                        <dd>{{ $readable }}</dd>
                                    </div>
                                @endif
                            @endforeach
                        @endif
                    </dl>

                    @if ($leaveRequest->purpose)
                        <div class="divider"></div>
                        <dt class="form-label mb-1">Reason / Purpose</dt>
                        <p class="mb-0">{{ $leaveRequest->purpose }}</p>
                    @endif
                    @if ($leaveRequest->is_late_filing)
                        <div class="divider"></div>
                        <dt class="form-label mb-1">Late Filing Reason</dt>
                        <p class="mb-0">{{ $leaveRequest->late_filing_reason }}</p>
                    @endif
                    @if ($leaveRequest->disapproval_reason)
                        <div class="authority-note restricted mt-3" style="background:var(--danger-bg);color:var(--danger);border-color:color-mix(in srgb,var(--danger) 25%,transparent)">
                            <i class="bi bi-x-octagon" aria-hidden="true"></i>
                            <span><span class="title">Disapproval reason</span>{{ $leaveRequest->disapproval_reason }}</span>
                        </div>
                    @endif
                    @if ($leaveRequest->filing_warnings)
                        <div class="alert alert-warning small mt-3 mb-0">
                            @foreach ($leaveRequest->filing_warnings as $warning)
                                <div><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>{{ $warning }}</div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            {{-- Supporting documents -------------------------------------- --}}
            <div class="card">
                <div class="card-header">Supporting Documents</div>
                @if ($leaveRequest->documents->isEmpty())
                    <div class="card-body pb-0">
                        @include('partials.empty-state', [
                            'icon' => 'bi-paperclip', 'title' => 'No documents uploaded',
                            'body' => 'Attachments required by the leave type will be listed here.',
                        ])
                    </div>
                @else
                    <ul class="list-group list-group-flush">
                        @foreach ($leaveRequest->documents as $doc)
                            <li class="list-group-item d-flex flex-wrap justify-content-between align-items-center gap-2">
                                <span>
                                    <i class="bi bi-paperclip me-1" aria-hidden="true"></i>
                                    <span class="cell-primary">{{ ucwords(str_replace('_', ' ', $doc->type)) }}</span>
                                    <span class="cell-meta">— {{ $doc->original_name }}</span>
                                </span>
                                <a href="{{ route('leave.documents.download', $doc) }}" class="btn btn-sm btn-outline-secondary">Download</a>
                            </li>
                        @endforeach
                    </ul>
                @endif
                @if ($leaveRequest->user_id === auth()->id() && ! $leaveRequest->isFinal())
                    <div class="card-body border-top" style="border-color:var(--border)!important">
                        <form method="POST" action="{{ route('leave.documents.store', $leaveRequest) }}" enctype="multipart/form-data" class="row g-2" data-no-loader>
                            @csrf
                            <div class="col-md-5">
                                <label class="visually-hidden" for="doc-type">Document type</label>
                                <input id="doc-type" name="type" class="form-control form-control-sm" placeholder="Document type" required>
                            </div>
                            <div class="col-md-5">
                                <label class="visually-hidden" for="doc-file">File</label>
                                <input id="doc-file" type="file" name="document" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png" required>
                            </div>
                            <div class="col-md-2"><button class="btn btn-sm btn-lgu w-100">Upload</button></div>
                        </form>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="hr-stack">
            {{-- Approval authority (existing workflow state) -------------- --}}
            <div class="card">
                <div class="card-header">Approval Authority</div>
                <div class="card-body">
                    @include('partials.approval-authority', ['request' => $leaveRequest])
                </div>
            </div>

            {{-- Approval history ------------------------------------------ --}}
            <div class="card">
                <div class="card-header">Approval History</div>
                <div class="card-body">
                    <ul class="flow">
                        <li>
                            <span class="node done" aria-hidden="true"></span>
                            <div class="step-title">Submitted</div>
                            <div class="step-meta">{{ $leaveRequest->user->name }} · {{ $leaveRequest->created_at->format('M j, Y g:i A') }}</div>
                        </li>
                        @foreach ($leaveRequest->approvals->sortBy('step_no') as $a)
                            @php
                                $roleLabel = ['department_head'=>'Department Head','hr'=>'HR Officer','mayor'=>'Municipal Mayor'][$a->role_slug] ?? ucwords(str_replace('_',' ',$a->role_slug));
                                $node = match ($a->action) {
                                    'approved', 'certified' => 'done',
                                    'rejected' => 'bad',
                                    'returned' => 'wait',
                                    default => '',
                                };
                            @endphp
                            <li>
                                <span class="node {{ $node }}" aria-hidden="true"></span>
                                <div class="step-title">{{ $roleLabel }}</div>
                                <div class="step-meta">
                                    @if ($a->action !== 'pending')
                                        {{ ucfirst($a->action) }} by {{ $a->approver?->name ?? '—' }} · {{ $a->acted_at?->format('M j, g:i A') }}
                                        @if ($a->comments)<div class="fst-italic mt-1">“{{ $a->comments }}”</div>@endif
                                        @if ($a->certified_balances)
                                            <div class="cell-num mt-1">Certified VL {{ $a->certified_balances['vacation_balance'] ?? '—' }} · SL {{ $a->certified_balances['sick_balance'] ?? '—' }}</div>
                                        @endif
                                    @else
                                        Awaiting action
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
