@extends('layouts.app')
@section('title', 'Blocked IPs')
@section('content')
<h1 class="h4 mb-3">Blocked IP Addresses</h1>
<div class="row g-3">
    <div class="col-lg-4"><div class="card"><div class="card-header fw-semibold">Block an IP</div><div class="card-body">
        <form method="POST" action="{{ route('security.block-ip') }}">
            @csrf
            <div class="mb-2"><label class="form-label">IP address</label><input name="ip" class="form-control" required></div>
            <div class="mb-2"><label class="form-label">Reason</label><input name="reason" class="form-control" required></div>
            <div class="mb-2"><label class="form-label">Duration (hours, blank = permanent)</label><input type="number" name="hours" class="form-control"></div>
            <button class="btn btn-danger w-100">Block IP</button>
        </form>
    </div></div></div>
    <div class="col-lg-8"><div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead><tr><th>IP</th><th>Reason</th><th>Source</th><th>Expires</th><th>Status</th><th></th></tr></thead>
        <tbody>
        @forelse ($blocked as $b)
            <tr>
                <td><code>{{ $b->ip }}</code></td>
                <td class="small">{{ $b->reason }}</td>
                <td><span class="badge bg-{{ $b->source==='auto'?'warning text-dark':'secondary' }}">{{ $b->source }}</span></td>
                <td class="small">{{ $b->expires_at ? $b->expires_at->format('M d, H:i') : 'Permanent' }}</td>
                <td>@if($b->active && (!$b->expires_at || $b->expires_at->isFuture()))<span class="badge bg-danger">Active</span>@else<span class="badge bg-success">Lifted</span>@endif</td>
                <td class="text-end">
                    @if($b->active)
                        <form method="POST" action="{{ route('security.unblock-ip',$b) }}" data-confirm="Unblock {{ $b->ip }}?">@csrf<button class="btn btn-sm btn-outline-success">Unblock</button></form>
                    @endif
                </td>
            </tr>
        @empty <tr><td colspan="6" class="text-center text-muted py-4">No blocked IPs.</td></tr> @endforelse
        </tbody></table></div><div class="card-body">{{ $blocked->links() }}</div></div></div>
</div>
@endsection
