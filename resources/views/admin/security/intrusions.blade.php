@extends('layouts.app')
@section('title', 'Intrusion Logs')
@section('content')
<h1 class="h4 mb-3">Intrusion Logs</h1>
<div class="card">
    <x-list-toolbar search placeholder="Search by IP" :action="route('security.intrusions')">
        <x-list-filter name="category" label="Category" :options="[
            'sqli' => 'SQLi', 'xss' => 'XSS', 'traversal' => 'Traversal',
            'csrf' => 'CSRF', 'rate' => 'Rate', 'auth_fail' => 'Auth failure',
            'device' => 'Device', 'privilege' => 'Privilege', 'other' => 'Other',
        ]" />
        <x-list-filter name="severity" label="Severity" :options="[
            'low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical',
        ]" />
    </x-list-toolbar>

    <div data-list>
    <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
    <thead><tr><th>Time</th><th>Category</th><th>Severity</th><th>IP</th><th>Route</th><th>User</th><th>Rule</th></tr></thead>
    <tbody>
    @forelse ($logs as $l)
        <tr>
            <td class="small">{{ $l->created_at->format('M d, H:i:s') }}</td>
            <td><span class="badge bg-secondary">{{ $l->category }}</span></td>
            <td><span class="badge bg-{{ ['low'=>'secondary','medium'=>'warning','high'=>'danger','critical'=>'dark'][$l->severity] ?? 'secondary' }}">{{ $l->severity }}</span></td>
            <td><code>{{ $l->ip }}</code></td>
            <td class="small text-truncate" style="max-width:200px">{{ $l->method }} /{{ $l->route }}</td>
            <td class="small">{{ $l->user?->name ?? '—' }}</td>
            <td class="small">{{ $l->matched_rule }}</td>
        </tr>
    @empty <tr><td colspan="7" class="text-center text-muted py-4">No intrusion events.</td></tr> @endforelse
    </tbody></table></div><div class="card-body">{{ $logs->links() }}</div></div></div>
@endsection
