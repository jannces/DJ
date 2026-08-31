@extends('layouts.app')
@section('title', 'All Leave Requests')
@section('content')
<h1 class="h4 mb-3">All Leave Requests</h1>
<div class="card">
    <x-list-toolbar search placeholder="Reference or employee"
        :action="route('leave.all')">
        <x-list-filter name="status" label="Status" :options="[
            'pending' => 'Pending', 'dept_review' => 'Department review',
            'hr_review' => 'HR review', 'final_review' => 'Final review',
            'approved' => 'Approved', 'rejected' => 'Rejected',
            'returned' => 'Returned', 'cancelled' => 'Cancelled',
        ]" />
        <x-list-filter name="type" label="Type" :options="$types" />
    </x-list-toolbar>

    <div data-list>
    <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
        <thead><tr><th>Reference</th><th>Employee</th><th>Type</th><th>Dates</th><th>Status</th><th></th></tr></thead>
        <tbody>
        @forelse ($requests as $r)
            <tr>
                <td class="fw-semibold">{{ $r->reference_no }}</td>
                {{-- The name goes where the row goes: this row is a leave
                     application, so the applicant's name opens the application
                     and not their HR record. One row, one destination. --}}
                <td>
                    <a href="{{ route('leave.show', $r) }}" class="name-link fw-semibold">{{ $r->user->name }}</a>
                </td>
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
</div><div class="card-body">{{ $requests->links() }}</div></div></div>
@endsection
