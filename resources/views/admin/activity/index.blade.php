@extends('layouts.app')
@section('title', 'Activity Logs')
@section('content')
<h1 class="h4 mb-3">Activity Logs</h1>
<form class="card card-body mb-3" method="GET" data-no-loader>
    <div class="row g-2 align-items-end">
        <div class="col-md-3"><label class="form-label small">User</label><input name="user" value="{{ request('user') }}" class="form-control form-control-sm"></div>
        <div class="col-md-2"><button class="btn btn-sm btn-lgu w-100">Filter</button></div>
    </div>
</form>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
    <thead><tr><th>Time</th><th>User</th><th>Method</th><th>Path</th><th>Route</th><th>IP</th></tr></thead>
    <tbody>
    @forelse ($logs as $l)
        <tr><td class="small">{{ $l->created_at->format('M d, H:i:s') }}</td>
            <td class="small">{{ $l->user?->name ?? '—' }}</td>
            <td><span class="badge bg-light text-dark">{{ $l->method }}</span></td>
            <td class="small">/{{ $l->path }}</td>
            <td class="small">{{ $l->route_name }}</td>
            <td class="small">{{ $l->ip }}</td></tr>
    @empty <tr><td colspan="6" class="text-center text-muted py-4">No activity.</td></tr> @endforelse
    </tbody></table></div><div class="card-body">{{ $logs->links() }}</div></div>
@endsection
