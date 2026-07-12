@extends('layouts.app')
@section('title', 'My Leave Requests')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">My Leave Requests</h1>
    <a href="{{ route('leave.create') }}" class="btn btn-lgu btn-sm"><i class="bi bi-calendar-plus me-1"></i>Apply</a>
</div>
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Reference</th><th>Type</th><th>Dates</th><th>Days</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse ($requests as $r)
                <tr>
                    <td class="fw-semibold">{{ $r->reference_no }}</td>
                    <td>{{ $r->leaveType->name }}</td>
                    <td class="small">{{ $r->start_date->format('M d') }} – {{ $r->end_date->format('M d, Y') }}</td>
                    <td>{{ rtrim(rtrim(number_format($r->working_days,1),'0'),'.') }}</td>
                    <td>@include('leave._status_badge', ['status' => $r->status])</td>
                    <td class="text-end"><a href="{{ route('leave.show', $r) }}" class="btn btn-sm btn-outline-secondary">View</a></td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted py-4">No requests yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-body">{{ $requests->links() }}</div>
</div>
@endsection
