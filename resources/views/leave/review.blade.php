@extends('layouts.app')
@section('title', $title)
@section('content')
<h1 class="h4 mb-3">{{ $title }}</h1>
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Reference</th><th>Employee</th><th>Type</th><th>Dates</th><th>Days</th><th></th></tr></thead>
            <tbody>
            @forelse ($requests as $r)
                <tr>
                    <td class="fw-semibold">{{ $r->reference_no }}</td>
                    <td>{{ $r->user->name }}<div class="text-muted small">{{ $r->user->employeeProfile?->department?->name }}</div></td>
                    <td>{{ $r->leaveType->name }}</td>
                    <td class="small">{{ $r->start_date->format('M d') }} – {{ $r->end_date->format('M d, Y') }}</td>
                    <td>{{ rtrim(rtrim(number_format($r->working_days,1),'0'),'.') }}</td>
                    <td class="text-end">
                        <a href="{{ route('leave.show', $r) }}" class="btn btn-sm btn-outline-secondary">View</a>
                        <button class="btn btn-sm btn-lgu" data-bs-toggle="modal" data-bs-target="#act{{ $r->id }}">Act</button>
                    </td>
                </tr>
                <div class="modal fade" id="act{{ $r->id }}" tabindex="-1">
                    <div class="modal-dialog">
                        <form method="POST" action="{{ route('review.act', $r) }}" class="modal-content" data-no-loader>
                            @csrf
                            <div class="modal-header"><h5 class="modal-title">{{ $r->reference_no }} — decision</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                            <div class="modal-body">
                                <p class="small text-muted">{{ $r->user->name }} · {{ $r->leaveType->name }} · {{ rtrim(rtrim(number_format($r->working_days,1),'0'),'.') }} day(s)</p>
                                <div class="mb-2">
                                    <label class="form-label">Decision</label>
                                    <select name="action" class="form-select" required>
                                        <option value="approved">{{ $queue==='department' ? 'Recommend approval' : ($queue==='hr' ? 'Certify & endorse' : 'Approve (final)') }}</option>
                                        <option value="returned">Return for revision</option>
                                        <option value="rejected">{{ $queue==='department' ? 'Recommend disapproval' : 'Disapprove' }}</option>
                                    </select>
                                </div>
                                @if ($queue === 'final')
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
                            <div class="modal-footer"><button class="btn btn-lgu">Submit decision</button></div>
                        </form>
                    </div>
                </div>
            @empty
                <tr><td colspan="6" class="text-center text-muted py-4">Nothing awaiting your action.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-body">{{ $requests->links() }}</div>
</div>
@endsection
