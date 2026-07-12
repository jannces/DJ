@extends('layouts.app')
@section('title', 'Audit Logs')
@section('content')
<h1 class="h4 mb-3">Audit Logs</h1>
<form class="card card-body mb-3" method="GET" data-no-loader>
    <div class="row g-2 align-items-end">
        <div class="col-md-3"><label class="form-label small">Action</label><input name="action" value="{{ request('action') }}" class="form-control form-control-sm"></div>
        <div class="col-md-3"><label class="form-label small">User</label><input name="user" value="{{ request('user') }}" class="form-control form-control-sm"></div>
        <div class="col-md-2"><button class="btn btn-sm btn-lgu w-100">Filter</button></div>
    </div>
</form>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
    <thead><tr><th>Time</th><th>User</th><th>Role</th><th>Action</th><th>Target</th><th>IP</th><th>Changes</th></tr></thead>
    <tbody>
    @forelse ($logs as $l)
        <tr>
            <td class="small">{{ $l->created_at->format('M d, H:i:s') }}</td>
            <td class="small">{{ $l->user?->name ?? 'system' }}</td>
            <td class="small">{{ $l->role_snapshot }}</td>
            <td><span class="badge bg-light text-dark">{{ $l->action }}</span></td>
            <td class="small">{{ class_basename($l->auditable_type) }} {{ $l->auditable_id }}</td>
            <td class="small">{{ $l->ip }}</td>
            <td class="small" style="max-width:280px">
                @if ($l->new_values)<details><summary class="text-muted">view</summary><pre class="small mb-0">{{ json_encode(['old'=>$l->old_values,'new'=>$l->new_values], JSON_PRETTY_PRINT) }}</pre></details>@endif
            </td>
        </tr>
    @empty <tr><td colspan="7" class="text-center text-muted py-4">No audit entries.</td></tr> @endforelse
    </tbody></table></div><div class="card-body">{{ $logs->links() }}</div></div>
@endsection
