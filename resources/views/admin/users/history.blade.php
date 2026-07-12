@extends('layouts.app')
@section('title', 'User history')
@section('content')
<h1 class="h4 mb-3">History — {{ $user->name }}</h1>
<div class="row g-3">
    <div class="col-lg-4">
        <div class="card"><div class="card-header fw-semibold">Failed login attempts</div>
            <ul class="list-group list-group-flush">
                @forelse ($logins as $l)
                    <li class="list-group-item small"><span class="badge bg-warning text-dark">{{ $l->reason }}</span>
                        {{ $l->ip }} &middot; {{ $l->occurred_at->format('M d, H:i') }}</li>
                @empty <li class="list-group-item text-muted small">None recorded.</li> @endforelse
            </ul></div>
    </div>
    <div class="col-lg-4">
        <div class="card"><div class="card-header fw-semibold">Audit history</div>
            <ul class="list-group list-group-flush">
                @forelse ($audits as $a)
                    <li class="list-group-item small"><span class="fw-semibold">{{ $a->action }}</span>
                        <div class="text-muted">{{ $a->created_at->format('M d, H:i') }} &middot; {{ $a->ip }}</div></li>
                @empty <li class="list-group-item text-muted small">None recorded.</li> @endforelse
            </ul></div>
    </div>
    <div class="col-lg-4">
        <div class="card"><div class="card-header fw-semibold">Activity</div>
            <ul class="list-group list-group-flush">
                @forelse ($activity as $act)
                    <li class="list-group-item small">{{ $act->method }} /{{ $act->path }}
                        <div class="text-muted">{{ $act->created_at->format('M d, H:i') }}</div></li>
                @empty <li class="list-group-item text-muted small">None recorded.</li> @endforelse
            </ul></div>
    </div>
</div>
@endsection
