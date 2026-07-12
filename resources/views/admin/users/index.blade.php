@extends('layouts.app')
@section('title', 'Users')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">User Accounts</h1>
    <a href="{{ route('users.create') }}" class="btn btn-lgu btn-sm"><i class="bi bi-person-plus me-1"></i>New user</a>
</div>

<form class="card card-body mb-3" method="GET" data-no-loader>
    <div class="row g-2 align-items-end">
        <div class="col-md-4">
            <label class="form-label small">Search</label>
            <input name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="name, email, username">
        </div>
        <div class="col-md-3">
            <label class="form-label small">Status</label>
            <select name="status" class="form-select form-select-sm">
                <option value="">Any</option>
                @foreach (['active','inactive','blocked'] as $s)
                    <option value="{{ $s }}" @selected(request('status')===$s)>{{ ucfirst($s) }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-3">
            <div class="form-check mt-4">
                <input class="form-check-input" type="checkbox" name="archived" value="1" id="arch" @checked(request('archived'))>
                <label class="form-check-label small" for="arch">Show archived</label>
            </div>
        </div>
        <div class="col-md-2">
            <button class="btn btn-sm btn-lgu w-100">Filter</button>
        </div>
    </div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Name</th><th>Roles</th><th>Department</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse ($users as $user)
                <tr>
                    <td>
                        <div class="fw-semibold">{{ $user->name }}</div>
                        <div class="text-muted small">{{ $user->email }}</div>
                    </td>
                    <td>@foreach ($user->roles as $r)<span class="badge bg-secondary">{{ $r->name }}</span> @endforeach</td>
                    <td>{{ $user->employeeProfile?->department?->name ?? '—' }}</td>
                    <td>
                        @php $color = ['active'=>'success','inactive'=>'secondary','blocked'=>'danger'][$user->status] ?? 'secondary'; @endphp
                        <span class="badge bg-{{ $color }} badge-status">{{ $user->status }}</span>
                    </td>
                    <td class="text-end">
                        @if ($user->trashed())
                            <form method="POST" action="{{ route('users.restore', $user->id) }}" class="d-inline">
                                @csrf<button class="btn btn-sm btn-outline-success"><i class="bi bi-arrow-counterclockwise"></i> Restore</button>
                            </form>
                        @else
                            <a href="{{ route('users.edit', $user) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                            <a href="{{ route('users.history', $user) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-clock-history"></i></a>
                            <div class="btn-group">
                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown"></button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li>
                                        <form method="POST" action="{{ route('users.reset-password', $user) }}" data-confirm="Reset this user's password?">
                                            @csrf<button class="dropdown-item">Reset password</button>
                                        </form>
                                    </li>
                                    @if ($user->status === 'blocked')
                                        <li><form method="POST" action="{{ route('users.unblock', $user) }}">@csrf<button class="dropdown-item text-success">Unblock</button></form></li>
                                    @else
                                        <li>
                                            <form method="POST" action="{{ route('users.block', $user) }}" onsubmit="this.reason.value=prompt('Reason for blocking:')||''; return this.reason.value!=='';">
                                                @csrf<input type="hidden" name="reason">
                                                <button class="dropdown-item text-danger">Block</button>
                                            </form>
                                        </li>
                                    @endif
                                    <li>
                                        <form method="POST" action="{{ route('users.toggle-active', $user) }}">@csrf<button class="dropdown-item">{{ $user->status==='inactive'?'Activate':'Deactivate' }}</button></form>
                                    </li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><form method="POST" action="{{ route('users.archive', $user) }}" data-confirm="Archive this user?">@csrf<button class="dropdown-item text-warning">Archive</button></form></li>
                                </ul>
                            </div>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-center text-muted py-4">No users found.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-body">{{ $users->links() }}</div>
</div>
@endsection
