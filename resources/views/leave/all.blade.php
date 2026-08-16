@extends('layouts.app')
@section('title', 'Leave Requests')
@section('content')
{{-- HR Management → Leave Requests. Same filters and query string as before. --}}
@include('partials.page-head', [
    'title' => 'Leave Requests',
    'sub' => 'Every leave application across the LGU',
    'crumbs' => ['Overview' => route('dashboard'), 'HR Management' => null, 'Leave Requests' => null],
])

@php $hasFilters = request()->filled('status') || request()->filled('type'); @endphp

<form class="card card-body mb-3" method="GET" data-no-loader role="search">
    <div class="filter-bar">
        <div class="field">
            <label for="f-status">Status</label>
            <select id="f-status" name="status" class="form-select form-select-sm">
                <option value="">Any status</option>
                @foreach (['pending','dept_review','hr_review','final_review','approved','rejected','returned','cancelled'] as $s)
                    <option value="{{ $s }}" @selected(request('status')===$s)>{{ ucfirst(str_replace('_',' ',$s)) }}</option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label for="f-type">Leave type</label>
            <select id="f-type" name="type" class="form-select form-select-sm">
                <option value="">Any type</option>
                @foreach ($types as $t)<option value="{{ $t->code }}" @selected(request('type')===$t->code)>{{ $t->name }}</option>@endforeach
            </select>
        </div>
        <div class="actions">
            <button class="btn btn-sm btn-lgu"><i class="bi bi-funnel" aria-hidden="true"></i>Apply filters</button>
            @if ($hasFilters)
                <a href="{{ route('leave.all') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
            @endif
        </div>
    </div>
</form>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>{{ number_format($requests->total()) }} request(s)</span>
        @if ($hasFilters)<span class="chip"><i class="bi bi-funnel" aria-hidden="true"></i>Filtered</span>@endif
    </div>
    <div class="table-scroll">
        <table class="table table-quiet">
            <thead>
                <tr>
                    <th scope="col">Reference</th>
                    <th scope="col">Employee</th>
                    <th scope="col">Leave Type</th>
                    <th scope="col">Dates</th>
                    <th scope="col">Status</th>
                    <th scope="col"><span class="visually-hidden">Actions</span></th>
                </tr>
            </thead>
            <tbody>
            @forelse ($requests as $r)
                <tr>
                    <td class="cell-primary cell-num">{{ $r->reference_no }}</td>
                    <td>{{ $r->user->name }}</td>
                    <td>{{ $r->leaveType->name }}</td>
                    <td class="cell-meta cell-num">{{ $r->start_date->format('M j') }} – {{ $r->end_date->format('M j, Y') }}</td>
                    <td>@include('partials.status-pill', ['status' => $r->status])</td>
                    <td class="text-end"><a href="{{ route('leave.show', $r) }}" class="btn btn-sm btn-outline-secondary">View</a></td>
                </tr>
            @empty
                <tr><td colspan="6">
                    @if ($hasFilters)
                        @include('partials.empty-state', [
                            'icon' => 'bi-search', 'title' => 'No Requests Found',
                            'body' => 'No leave requests match your current filters.',
                            'actionLabel' => 'Clear Filters', 'actionUrl' => route('leave.all'),
                        ])
                    @else
                        @include('partials.empty-state', [
                            'icon' => 'bi-inbox', 'title' => 'No leave requests yet',
                            'body' => 'Applications filed by employees will appear here.',
                        ])
                    @endif
                </td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if ($requests->hasPages())
        <div class="card-body">{{ $requests->links() }}</div>
    @endif
</div>
@endsection
