@extends('layouts.app')
@section('title', 'Blocked IPs')
@section('content')

{{-- The block form used to hold the left third of the page permanently. It is
     behind the button now: blocking an address by hand is the rare thing, and
     the list of who is currently kept out is what the page is for. --}}

@php
    $openNew = ($opening ?? false) || $errors->any();
@endphp

<div class="list-head">
    <h1 class="h4 mb-0">Blocked IP Addresses</h1>
</div>

<div class="list-actions">
    <a href="{{ route('security.blocked-ips') }}" class="btn btn-danger btn-sm"
       data-bs-toggle="modal" data-bs-target="#block-new">
        <i class="bi bi-slash-circle"></i>Block an IP
    </a>
</div>

<div class="card">
    <x-list-toolbar search placeholder="Search IP or reason"
        :action="route('security.blocked-ips')">
        <x-list-filter name="source" label="Source"
            :options="['auto' => 'Automatic', 'manual' => 'By an administrator']" />
        <x-list-filter name="show" label="Show" any="In effect"
            :options="['lifted' => 'Lifted', 'all' => 'All']" />
    </x-list-toolbar>

    <div data-list>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>IP</th><th>Reason</th><th>Source</th>
                    <th>Until</th><th>Status</th><th></th>
                </tr>
            </thead>
            <tbody>
            @forelse ($blocked as $b)
                @php $inEffect = $b->isInEffect(); @endphp
                <tr>
                    <td><code>{{ $b->ip }}</code></td>
                    <td class="small">
                        {{ $b->reason }}
                        @if ($b->blocker)
                            <div class="text-muted">by {{ $b->blocker->name }}</div>
                        @endif
                    </td>
                    <td>
                        <span class="badge bg-{{ $b->source === 'auto' ? 'warning text-dark' : 'secondary' }}">
                            {{ $b->source === 'auto' ? 'automatic' : 'manual' }}
                        </span>
                    </td>
                    {{-- "Expires" over a date that has already passed reads as
                         though it still will. --}}
                    <td class="small">
                        @if (! $b->expires_at)
                            Permanent
                        @elseif ($inEffect)
                            {{ $b->expires_at->diffForHumans(['parts' => 1]) }}
                        @else
                            {{ $b->expires_at->format('M d, H:i') }}
                        @endif
                    </td>
                    <td>
                        @if ($inEffect)
                            <span class="badge bg-danger">Blocked</span>
                        @else
                            <span class="badge bg-success">Lifted</span>
                        @endif
                    </td>
                    <td class="text-end text-nowrap">
                        {{-- One button, and which one depends on the state. The
                             colour is the colour of what it does: red keeps an
                             address out, green lets it back in. --}}
                        @if ($inEffect)
                            <form method="POST" action="{{ route('security.unblock-ip', $b) }}"
                                  class="d-inline"
                                  data-confirm="Lift the block on {{ $b->ip }}? It will be able to reach the system again."
                                  data-confirm-tone="success">
                                @csrf
                                <button class="btn btn-sm btn-success">
                                    <i class="bi bi-unlock"></i>Lift block
                                </button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('security.reblock-ip', $b) }}"
                                  class="d-inline"
                                  data-confirm="Block {{ $b->ip }} again? It will be shut out of the system."
                                  data-confirm-tone="danger">
                                @csrf
                                <button class="btn btn-sm btn-danger">
                                    <i class="bi bi-slash-circle"></i>Block again
                                </button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted py-4">No addresses are blocked.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-body">{{ $blocked->links() }}</div>
    </div>
</div>

<x-record-panel id="block-new" title="Block an IP"
    :action="route('security.block-ip')" save="Block IP"
    :open="$openNew" :cancel="route('security.blocked-ips')">

    <div class="mb-3">
        <label class="form-label" for="blk-ip">IP address <span class="req">*</span></label>
        <input id="blk-ip" name="ip" required
               class="form-control @error('ip') is-invalid @enderror"
               value="{{ old('ip') }}" placeholder="192.168.1.24">
        @error('ip')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="mb-3">
        <label class="form-label" for="blk-reason">Reason <span class="req">*</span></label>
        <input id="blk-reason" name="reason" required maxlength="255"
               class="form-control @error('reason') is-invalid @enderror"
               value="{{ old('reason') }}">
        @error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
        <div class="form-text">Recorded against the block, and shown in this list.</div>
    </div>

    <div class="mb-0">
        <label class="form-label" for="blk-hours">Duration in hours</label>
        <input id="blk-hours" type="number" min="1" name="hours"
               class="form-control @error('hours') is-invalid @enderror"
               value="{{ old('hours') }}" placeholder="24">
        @error('hours')<div class="invalid-feedback">{{ $message }}</div>@enderror
        <div class="form-text">Leave blank to block permanently.</div>
    </div>
</x-record-panel>

@endsection
