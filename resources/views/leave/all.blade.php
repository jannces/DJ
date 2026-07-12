@extends('layouts.app')
@section('title', 'All Leave Requests')
@section('content')
<h1 class="h4 mb-3">All Leave Requests</h1>
<form class="card card-body mb-3" method="GET" data-no-loader>
    <div class="row g-2 align-items-end">
        <div class="col-md-3"><label class="form-label small">Status</label>
            <select name="status" class="form-select form-select-sm">
                <option value="">Any</option>
                @foreach (['pending','dept_review','hr_review','final_review','approved','rejected','returned','cancelled'] as $s)
                    <option value="{{ $s }}" @selected(request('status')===$s)>{{ ucfirst(str_replace('_',' ',$s)) }}</option>
                @endforeach
            </select></div>
        <div class="col-md-3"><label class="form-label small">Type</label>
            <select name="type" class="form-select form-select-sm">
                <option value="">Any</option>
                @foreach ($types as $t)<option value="{{ $t->code }}" @selected(request('type')===$t->code)>{{ $t->name }}</option>@endforeach
            </select></div>
        <div class="col-md-2"><button class="btn btn-sm btn-lgu w-100">Filter</button></div>
    </div>
</form>
<div class="card"><div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
        <thead><tr><th>Reference</th><th>Employee</th><th>Type</th><th>Dates</th><th>Status</th><th></th></tr></thead>
        <tbody>
        @forelse ($requests as $r)
            <tr>
                <td class="fw-semibold">{{ $r->reference_no }}</td>
                <td>{{ $r->user->name }}</td>
                <td>{{ $r->leaveType->name }}</td>
                <td class="small">{{ $r->start_date->format('M d') }} – {{ $r->end_date->format('M d, Y') }}</td>
                <td>@include('leave._status_badge', ['status' => $r->status])</td>
                <td class="text-end"><a href="{{ route('leave.show', $r) }}" class="btn btn-sm btn-outline-secondary">View</a></td>
            </tr>
        @empty
            <tr><td colspan="6" class="text-center text-muted py-4">No requests found.</td></tr>
        @endforelse
        </tbody>
    </table>
</div><div class="card-body">{{ $requests->links() }}</div></div>
@endsection
