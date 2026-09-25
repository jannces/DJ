@extends('layouts.app')
@section('title', 'Activity Logs')
@section('content')
<h1 class="h4 mb-3">Activity Logs</h1>
<div class="card">
    <x-list-toolbar search placeholder="Search by user"
        :action="route('activity.index')">
        <x-list-filter name="method" label="Method" :options="$methods" />
    </x-list-toolbar>

    <div data-list>
    <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
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
    </tbody></table></div><div class="card-body">{{ $logs->links() }}</div></div></div>
@endsection
