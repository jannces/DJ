@extends('layouts.app')
@section('title', 'My Leave Requests')
@section('content')
<div class="page-head">
    <nav class="crumbs" aria-label="breadcrumb">
        <a href="{{ route('dashboard') }}">Overview</a><span class="sep">/</span>
        <span>Leave</span><span class="sep">/</span><span>My Leave Requests</span>
    </nav>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h1>My Leave Requests</h1>
            <div class="sub">Applications you have filed</div>
        </div>
        @can('leave.apply')
            <a href="{{ route('leave.create') }}" class="btn btn-lgu"><i class="bi bi-calendar-plus" aria-hidden="true"></i>Apply for Leave</a>
        @endcan
    </div>
</div>

<div class="card">
    <div class="table-scroll">
        <table class="table table-quiet">
            <thead>
                <tr>
                    <th scope="col">Reference</th>
                    <th scope="col">Leave Type</th>
                    <th scope="col">Dates</th>
                    <th scope="col" class="text-end">Days</th>
                    <th scope="col">Classification</th>
                    <th scope="col">Status</th>
                    <th scope="col"><span class="visually-hidden">Actions</span></th>
                </tr>
            </thead>
            <tbody>
            @forelse ($requests as $r)
                <tr>
                    <td class="cell-primary cell-num">{{ $r->reference_no }}</td>
                    <td>{{ $r->leaveType->name }}</td>
                    <td class="cell-meta cell-num">{{ $r->start_date->format('M j') }} – {{ $r->end_date->format('M j, Y') }}</td>
                    <td class="text-end cell-num">{{ rtrim(rtrim(number_format($r->working_days, 1), '0'), '.') }}</td>
                    <td class="cell-meta">{{ $r->leaveType->deductible ? 'Deductible' : 'Non-deductible' }}</td>
                    <td>@include('partials.status-pill', ['status' => $r->status])</td>
                    <td class="text-end"><a href="{{ route('leave.show', $r) }}" class="btn btn-sm btn-outline-secondary">View</a></td>
                </tr>
            @empty
                <tr><td colspan="7">@include('partials.empty-state', [
                    'icon' => 'bi-calendar-x',
                    'title' => 'No Leave Requests',
                    'body' => "You haven't submitted any leave requests yet.",
                    'actionLabel' => 'Apply for Leave',
                    'actionUrl' => route('leave.create'),
                ])</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if ($requests->hasPages())
        <div class="card-body">{{ $requests->links() }}</div>
    @endif
</div>
@endsection
