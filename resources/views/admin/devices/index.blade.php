@extends('layouts.app')
@section('title', 'Authorized Devices')
@section('content')

{{-- The register form used to hold the left third of the page permanently and
     squeeze the list into what was left. It is behind the button now, and the
     button sits inside the container above the list it adds to. --}}

@php
    $openNew = ($opening ?? false) || $errors->any();
    $archived = request()->boolean('archived');
@endphp

<div class="list-head">
    <h1 class="h4 mb-0">Authorized Devices</h1>
    <span class="badge bg-{{ $enforcement ? 'success' : 'secondary' }}">
        Enforcement: {{ $enforcement ? 'ON' : 'OFF' }}
    </span>
</div>

@unless ($enforcement)
    <div class="alert alert-info small">
        Device enforcement is currently OFF. Turn it on in
        <a href="{{ route('settings.index') }}">System Settings</a>
        once all office computers are registered.
    </div>
@endunless

<div class="card">
    <div class="list-toolbar">
        <a href="{{ route('devices.create') }}" class="btn btn-lgu btn-sm"
           data-bs-toggle="modal" data-bs-target="#device-new">
            <i class="bi bi-plus-lg"></i>Register device
        </a>

        <form method="GET" class="toolbar-filters" data-no-loader>
            <div class="input-group input-group-sm" style="max-width:280px">
                <input name="q" value="{{ request('q') }}" class="form-control"
                       placeholder="Search IP or hostname" aria-label="Search IP or hostname">
                <button class="btn btn-outline-secondary">Search</button>
            </div>
            {{-- Archived devices are already in the query; nothing linked to
                 them, so the only way to see one was to type the URL. --}}
            @if ($archived)
                <a href="{{ route('devices.index', ['q' => request('q')]) }}"
                   class="btn btn-sm btn-outline-secondary">Hide archived</a>
            @else
                <a href="{{ route('devices.index', ['q' => request('q'), 'archived' => 1]) }}"
                   class="btn btn-sm btn-outline-secondary">Show archived</a>
            @endif
        </form>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>IP</th><th>Hostname</th><th>Status</th>
                    <th>Online</th><th>Last active</th><th></th>
                </tr>
            </thead>
            <tbody>
            @forelse ($devices as $d)
                <tr>
                    <td><code>{{ $d->ip_address }}</code></td>
                    <td>
                        {{ $d->hostname }}
                        @if ($d->description)
                            <div class="text-muted small">{{ $d->description }}</div>
                        @endif
                    </td>
                    <td>
                        <span class="badge bg-{{ $d->status === 'active' ? 'success' : 'secondary' }}">
                            {{ $d->status }}
                        </span>
                        @if ($d->archived_at)
                            <span class="badge bg-warning">archived</span>
                        @endif
                    </td>
                    <td>
                        @if ($d->isOnline())
                            <span class="badge bg-success">● Online</span>
                        @else
                            <span class="badge bg-secondary">○ Offline</span>
                        @endif
                    </td>
                    <td class="small">{{ $d->last_active_at?->diffForHumans() ?? 'Never' }}</td>
                    <td class="text-end text-nowrap">
                        <form method="POST" action="{{ route('devices.toggle', $d) }}" class="d-inline">
                            @csrf
                            <button class="btn btn-sm btn-outline-secondary">
                                {{ $d->status === 'active' ? 'Deactivate' : 'Activate' }}
                            </button>
                        </form>
                        @unless ($d->archived_at)
                            <form method="POST" action="{{ route('devices.archive', $d) }}"
                                  class="d-inline" data-confirm="Archive {{ $d->hostname }}?">
                                @csrf
                                <button class="btn btn-sm btn-outline-warning"
                                        aria-label="Archive {{ $d->hostname }}"><i class="bi bi-archive"></i></button>
                            </form>
                        @endunless
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted py-4">No devices registered.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-body">{{ $devices->links() }}</div>
</div>

<x-record-panel id="device-new" title="Register device"
    :action="route('devices.store')" save="Register"
    :open="$openNew" :cancel="route('devices.index')">
    @include('admin.devices._fields')
</x-record-panel>

@endsection
