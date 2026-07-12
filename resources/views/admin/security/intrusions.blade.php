@extends('layouts.app')
@section('title', 'Intrusion Logs')
@section('content')
<h1 class="h4 mb-3">Intrusion Logs</h1>
<form class="card card-body mb-3" method="GET" data-no-loader>
    <div class="row g-2 align-items-end">
        <div class="col-md-3"><label class="form-label small">Category</label>
            <select name="category" class="form-select form-select-sm"><option value="">Any</option>
                @foreach (['sqli','xss','traversal','csrf','rate','auth_fail','device','privilege','other'] as $c)
                    <option value="{{ $c }}" @selected(request('category')===$c)>{{ strtoupper($c) }}</option>@endforeach
            </select></div>
        <div class="col-md-3"><label class="form-label small">Severity</label>
            <select name="severity" class="form-select form-select-sm"><option value="">Any</option>
                @foreach (['low','medium','high','critical'] as $s)<option value="{{ $s }}" @selected(request('severity')===$s)>{{ ucfirst($s) }}</option>@endforeach
            </select></div>
        <div class="col-md-3"><label class="form-label small">IP</label>
            <input name="ip" value="{{ request('ip') }}" class="form-control form-control-sm"></div>
        <div class="col-md-2"><button class="btn btn-sm btn-lgu w-100">Filter</button></div>
    </div>
</form>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
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
    </tbody></table></div><div class="card-body">{{ $logs->links() }}</div></div>
@endsection
