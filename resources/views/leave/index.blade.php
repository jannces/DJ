@extends('layouts.app')
@section('title', 'My Leave Requests')
@section('content')
{{-- No "Apply" button here: the sidebar already carries "Apply for Leave", and
     a second entry point for the same action is just duplication. --}}
<h1 class="h4 mb-3">My Leave Requests</h1>
<div class="card">
    <x-list-toolbar :action="route('leave.index')">
        <x-list-filter name="status" label="Status" :options="[
            'pending' => 'Pending',
            // Kept so applications filed under the old two-step flow are still
            // findable by their recorded status. Nothing lands here now.
            'dept_review' => 'Department review (archived flow)',
            'hr_review' => 'HR review', 'final_review' => 'Final review',
            'approved' => 'Approved', 'rejected' => 'Rejected',
            'returned' => 'Returned', 'cancelled' => 'Cancelled',
        ]" />
    </x-list-toolbar>

    <div data-list>
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
                    {{-- One destination per row. The form preview now carries the
                         filed form, the details and the approval progress, so the
                         separate Timeline and Details buttons were three clicks
                         to three pages that belong together. --}}
                    <td class="text-end">
                        <a href="{{ route('leave.preview-form', $r) }}" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-file-earmark-text me-1"></i>View Form
                        </a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted py-4">No requests yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-body">{{ $requests->links() }}</div>
    </div>
</div>
@endsection
