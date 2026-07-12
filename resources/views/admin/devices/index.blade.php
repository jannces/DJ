@extends('layouts.app')
@section('title', 'Authorized Devices')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Authorized Devices</h1>
    <span class="badge bg-{{ $enforcement ? 'success' : 'secondary' }}">Enforcement: {{ $enforcement ? 'ON' : 'OFF' }}</span>
</div>
@unless ($enforcement)
    <div class="alert alert-info small">Device enforcement is currently OFF. Turn it on in <a href="{{ route('settings.index') }}">System Settings</a> once all office computers are registered.</div>
@endunless
<div class="row g-3">
    <div class="col-lg-4"><div class="card"><div class="card-header fw-semibold">Register device</div><div class="card-body">
        <form method="POST" action="{{ route('devices.store') }}">
            @csrf
            <div class="mb-2"><label class="form-label">IP address</label><input name="ip_address" class="form-control" required></div>
            <div class="mb-2"><label class="form-label">Hostname</label><input name="hostname" class="form-control" required></div>
            <div class="mb-2"><label class="form-label">MAC address (optional)</label><input name="mac_address" class="form-control"></div>
            <div class="mb-2"><label class="form-label">Description</label><input name="description" class="form-control"></div>
            <button class="btn btn-lgu w-100">Register</button>
        </form>
    </div></div></div>
    <div class="col-lg-8"><div class="card">
        <form class="card-body pb-0" method="GET" data-no-loader>
            <div class="input-group input-group-sm mb-2" style="max-width:320px">
                <input name="q" value="{{ request('q') }}" class="form-control" placeholder="Search IP or hostname">
                <button class="btn btn-outline-secondary">Search</button>
            </div>
        </form>
        <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead><tr><th>IP</th><th>Hostname</th><th>Status</th><th>Online</th><th>Last active</th><th></th></tr></thead>
        <tbody>
        @forelse ($devices as $d)
            <tr>
                <td><code>{{ $d->ip_address }}</code></td>
                <td>{{ $d->hostname }}<div class="text-muted small">{{ $d->description }}</div></td>
                <td><span class="badge bg-{{ $d->status==='active'?'success':'secondary' }}">{{ $d->status }}</span></td>
                <td>@if($d->isOnline())<span class="badge bg-success">● Online</span>@else<span class="badge bg-secondary">○ Offline</span>@endif</td>
                <td class="small">{{ $d->last_active_at?->diffForHumans() ?? 'Never' }}</td>
                <td class="text-end">
                    <form method="POST" action="{{ route('devices.toggle',$d) }}" class="d-inline">@csrf<button class="btn btn-sm btn-outline-secondary">{{ $d->status==='active'?'Deactivate':'Activate' }}</button></form>
                    <form method="POST" action="{{ route('devices.archive',$d) }}" class="d-inline" data-confirm="Archive device?">@csrf<button class="btn btn-sm btn-outline-warning"><i class="bi bi-archive"></i></button></form>
                </td>
            </tr>
        @empty <tr><td colspan="6" class="text-center text-muted py-4">No devices registered.</td></tr> @endforelse
        </tbody></table></div><div class="card-body">{{ $devices->links() }}</div></div></div>
</div>
@endsection
