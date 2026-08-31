@extends('layouts.app')
@section('title', $title)
@section('content')
{{-- A sidebar page, so no back link — but the same header shape as everything
     else, rather than its own hand-written one. --}}
{{-- The same page for both officers; what changes is what it can act on and
     what the words mean. A head recommends and the application travels on
     either way; the Mayor and HR decide, and the first decision is final. --}}
<x-page-head :title="$title" :sub="$decides
    ? 'Any one of the Municipal Mayor or the HR Office may decide an application. The first decision is final.'
    : 'Applications from your office, waiting on your recommendation. Either way it goes on to the Mayor or HR — your comment goes with it.'" />
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Reference</th><th>Employee</th><th>Type</th><th>Dates</th><th>Days</th><th></th></tr></thead>
            <tbody>
            @forelse ($requests as $r)
                <tr>
                    <td class="fw-semibold">{{ $r->reference_no }}</td>
                    {{-- Same destination as the View button beside it. An
                         officer working down this queue clicks a name meaning
                         "show me that request", and that is where it goes. --}}
                    <td>
                        <a href="{{ route('leave.show', $r) }}" class="name-link fw-semibold">{{ $r->user->name }}</a>
                        <div class="text-muted small">{{ $r->user->employeeProfile?->department?->name }}</div>
                    </td>
                    <td>{{ $r->leaveType->name }}</td>
                    <td class="small">{{ $r->start_date->format('M d') }} – {{ $r->end_date->format('M d, Y') }}</td>
                    <td>{{ rtrim(rtrim(number_format($r->working_days,1),'0'),'.') }}</td>
                    <td class="text-end">
                        <a href="{{ route('leave.show', $r) }}" class="btn btn-sm btn-outline-secondary">View</a>
                        <button class="btn btn-sm btn-lgu" data-bs-toggle="modal" data-bs-target="#act{{ $r->id }}">
                            {{ $decides ? 'Act' : 'Recommend' }}
                        </button>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted py-4">Nothing awaiting your action.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-body">{{ $requests->links() }}</div>
</div>

{{-- Modals live outside the table (valid HTML + correct stacking so they are clickable). --}}
@foreach ($requests as $r)
    <div class="modal fade" id="act{{ $r->id }}" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form method="POST" action="{{ route('review.act', $r) }}" class="modal-content" data-no-loader>
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title">{{ $r->reference_no }} — {{ $decides ? 'decision' : 'recommendation' }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted">{{ $r->user->name }} · {{ $r->leaveType->name }} · {{ rtrim(rtrim(number_format($r->working_days,1),'0'),'.') }} day(s)</p>
                    <div class="mb-2">
                        <label class="form-label">{{ $decides ? 'Decision' : 'Recommendation' }}</label>
                        <select name="action" class="form-select" required>
                            @if ($decides)
                                <option value="approved">Approve</option>
                                <option value="returned">Return for revision</option>
                                <option value="rejected">Disapprove</option>
                            @else
                                <option value="approved">Endorse</option>
                                <option value="returned">Return for revision</option>
                                <option value="rejected">Do not endorse</option>
                            @endif
                        </select>
                        @unless ($decides)
                            <div class="form-text">
                                Endorsed or not, this goes on to the Mayor or HR &mdash; they decide.
                                Returning it sends it back to the employee instead.
                            </div>
                        @endunless
                    </div>
                    @if ($decides)
                        {{-- The pay split is part of a decision, not a
                             recommendation: it is what gets deducted. --}}
                        <div class="row g-2 mb-2">
                            <div class="col"><label class="form-label small">Days with pay</label>
                                <input type="number" step="0.5" name="days_with_pay" class="form-control" value="{{ $r->working_days }}"></div>
                            <div class="col"><label class="form-label small">Days without pay</label>
                                <input type="number" step="0.5" name="days_without_pay" class="form-control" value="0"></div>
                        </div>
                    @endif
                    <div class="mb-2"><label class="form-label">Comments / remarks</label>
                        <textarea name="comments" class="form-control" rows="2"></textarea></div>
                    <div class="mb-0"><label class="form-label">Signature (type your name)</label>
                        <input name="signature" class="form-control" value="{{ auth()->user()->name }}"></div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-lgu">{{ $decides ? 'Submit decision' : 'Submit recommendation' }}</button>
                </div>
            </form>
        </div>
    </div>
@endforeach
@endsection
