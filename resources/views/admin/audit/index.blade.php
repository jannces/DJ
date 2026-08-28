@extends('layouts.app')
@section('title', 'Audit Logs')
@section('content')
<h1 class="h4 mb-3">Audit Logs</h1>
<div class="card">
    <x-list-toolbar search placeholder="Search by user"
        :action="route('audit.index')">
        <x-list-filter name="action" label="Action" :options="$actions" />
    </x-list-toolbar>

    <div data-list>
    <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
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
    </tbody></table></div><div class="card-body">{{ $logs->links() }}</div></div></div>
@endsection
