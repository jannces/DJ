@extends('layouts.app')
@section('title', $title)
@section('content')
{{--
    HR Management → Leave Approvals.
    Same queue, same decision form and same route as before — only the
    presentation of the existing approval state has been reorganised.
--}}
@include('partials.page-head', [
    'title' => 'Leave Approvals',
    'sub' => 'Applications the workflow has placed at the HR step',
    'crumbs' => ['Overview' => route('dashboard'), 'HR Management' => null, 'Leave Approvals' => null],
])

<div class="context-bar ctx-org">
    <span class="ctx-label"><i class="bi bi-clipboard-check me-1" aria-hidden="true"></i>Approval queue</span>
    <span>{{ $requests->total() }} application(s) awaiting HR action. Authority is determined by the approval workflow, not by this screen.</span>
</div>

<div class="card">
    <div class="table-scroll">
        <table class="table table-quiet">
            <thead>
                <tr>
                    <th scope="col">Reference</th>
                    <th scope="col">Employee</th>
                    <th scope="col">Leave Type</th>
                    <th scope="col">Dates</th>
                    <th scope="col" class="text-end">Days</th>
                    <th scope="col">Stage</th>
                    <th scope="col"><span class="visually-hidden">Actions</span></th>
                </tr>
            </thead>
            <tbody>
            @forelse ($requests as $r)
                <tr>
                    <td class="cell-primary cell-num">{{ $r->reference_no }}</td>
                    <td>
                        <div class="cell-primary">{{ $r->user->name }}</div>
                        <div class="cell-meta">{{ $r->user->employeeProfile?->department?->name ?? '—' }}</div>
                    </td>
                    <td>{{ $r->leaveType->name }}</td>
                    <td class="cell-meta cell-num">{{ $r->start_date->format('M j') }} – {{ $r->end_date->format('M j, Y') }}</td>
                    <td class="text-end cell-num">{{ rtrim(rtrim(number_format($r->working_days, 1), '0'), '.') }}</td>
                    <td>@include('partials.status-pill', ['status' => $r->status])</td>
                    <td class="text-end text-nowrap">
                        <a href="{{ route('leave.show', $r) }}" class="btn btn-sm btn-outline-secondary">View Details</a>
                        <button class="btn btn-sm btn-lgu" data-bs-toggle="modal" data-bs-target="#act{{ $r->id }}">Act</button>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7">@include('partials.empty-state', [
                    'icon' => 'bi-inbox',
                    'title' => 'Nothing awaiting your action',
                    'body' => 'Applications appear here once the workflow places them at the HR step.',
                    'actionLabel' => 'View all leave requests',
                    'actionUrl' => route('leave.all'),
                ])</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if ($requests->hasPages())
        <div class="card-body">{{ $requests->links() }}</div>
    @endif
</div>

{{-- Decision modals: identical fields, route and payload as before. --}}
@foreach ($requests as $r)
    <div class="modal fade" id="act{{ $r->id }}" tabindex="-1" aria-labelledby="actLabel{{ $r->id }}" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <form method="POST" action="{{ route('review.act', $r) }}" class="modal-content" data-no-loader>
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title" id="actLabel{{ $r->id }}">{{ $r->reference_no }} — decision</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <dl class="def-grid mb-3">
                        <div><dt>Employee</dt><dd>{{ $r->user->name }}</dd></div>
                        <div><dt>Leave Type</dt><dd>{{ $r->leaveType->name }}</dd></div>
                        <div><dt>Inclusive Dates</dt><dd class="cell-num">{{ $r->start_date->format('M j') }} – {{ $r->end_date->format('M j, Y') }}</dd></div>
                        <div><dt>Working Days</dt><dd class="cell-num">{{ rtrim(rtrim(number_format($r->working_days, 1), '0'), '.') }}</dd></div>
                    </dl>

                    <div class="mb-3">
                        <label class="form-label" for="action{{ $r->id }}">Decision</label>
                        <select id="action{{ $r->id }}" name="action" class="form-select" required>
                            <option value="approved">Certify &amp; endorse</option>
                            <option value="returned">Return for revision</option>
                            <option value="rejected">Disapprove</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="comments{{ $r->id }}">Comments / remarks</label>
                        <textarea id="comments{{ $r->id }}" name="comments" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="mb-0">
                        <label class="form-label" for="signature{{ $r->id }}">Signature (type your name)</label>
                        <input id="signature{{ $r->id }}" name="signature" class="form-control" value="{{ auth()->user()->name }}">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-lgu">Submit decision</button>
                </div>
            </form>
        </div>
    </div>
@endforeach
@endsection
