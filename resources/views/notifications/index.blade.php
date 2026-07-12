@extends('layouts.app')
@section('title', 'Notifications')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Notifications</h1>
    <form method="POST" action="{{ route('notifications.read-all') }}" data-no-loader>@csrf<button class="btn btn-sm btn-outline-secondary">Mark all read</button></form>
</div>
<div class="card"><ul class="list-group list-group-flush">
    @forelse ($notifications as $n)
        <li class="list-group-item d-flex justify-content-between align-items-start {{ $n->read_at ? '' : 'bg-body-tertiary' }}">
            <div>
                <div class="fw-semibold">{{ $n->data['title'] ?? 'Notification' }}</div>
                <div class="small text-muted">{{ $n->data['message'] ?? '' }}</div>
                <div class="small text-muted">{{ $n->created_at->diffForHumans() }}</div>
            </div>
            @unless ($n->read_at)
                <form method="POST" action="{{ route('notifications.read', $n->id) }}" data-no-loader>@csrf<button class="btn btn-sm btn-link">Mark read</button></form>
            @endunless
        </li>
    @empty
        <li class="list-group-item text-muted text-center py-4">No notifications.</li>
    @endforelse
</ul></div>
<div class="mt-3">{{ $notifications->links() }}</div>
@endsection
