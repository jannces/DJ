@extends('layouts.app')
@section('title', 'My Balances')
@section('content')
<h1 class="h4 mb-3">My Leave Balances</h1>
<div class="row g-3 mb-3">
    @foreach ($balances as $b)
        <div class="col-md-3">
            <div class="card"><div class="card-body">
                <div class="text-muted small">{{ $b->leaveType->name }}</div>
                <div class="h4 mb-0">{{ number_format($b->balance, 2) }}</div>
                <div class="small text-muted">Earned {{ number_format($b->earned,2) }} · Used {{ number_format($b->used,2) }}</div>
            </div></div>
        </div>
    @endforeach
</div>
<div class="card">
    <div class="card-header fw-semibold">Credit history</div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead><tr><th>Date</th><th>Type</th><th>Entry</th><th class="text-end">Days</th><th class="text-end">Balance</th><th>Remarks</th></tr></thead>
            <tbody>
            @forelse ($history as $h)
                <tr>
                    <td class="small">{{ $h->created_at->format('M d, Y') }}</td>
                    <td>{{ $h->leaveType->code }}</td>
                    <td><span class="badge bg-light text-dark">{{ $h->entry_type }}</span></td>
                    <td class="text-end {{ $h->days < 0 ? 'text-danger' : 'text-success' }}">{{ number_format($h->days, 2) }}</td>
                    <td class="text-end">{{ number_format($h->balance_after, 2) }}</td>
                    <td class="small">{{ $h->remarks }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted py-3">No history yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
