@extends('layouts.app')
@section('title', 'Users')
@section('content')
<div class="list-head">
    <h1 class="h4 mb-0">User Accounts</h1>
</div>

{{-- New user stays a page rather than a panel: twenty-one fields across four
     sections is not something to read through a modal. The button sits where
     every other list's add button sits. --}}
<div class="list-actions">
    <a href="{{ route('users.create') }}" class="btn btn-lgu btn-sm">
        <i class="bi bi-person-plus"></i>New user
    </a>
</div>

<div class="card">
    <x-list-toolbar search placeholder="name, email, username"
        :action="route('users.index')">
        <x-list-filter name="role" label="Role" :options="$roles" />
        <x-list-filter name="status" label="Status"
            :options="['active' => 'Active', 'inactive' => 'Inactive', 'blocked' => 'Blocked']" />
        <x-list-filter name="show" label="Show" any="Current"
            :options="['archived' => 'Archived', 'all' => 'All']" />
    </x-list-toolbar>

    <div data-list>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Name</th><th>Roles</th><th>Department</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse ($users as $user)
                <tr>
                    <td>
                        {{-- An archived account has no edit page to open, so it
                             keeps a plain name rather than a link that refuses. --}}
                        @if ($user->trashed())
                            <div class="fw-semibold">{{ $user->name }}</div>
                        @else
                            <div class="fw-semibold">
                                <a href="{{ route('users.edit', $user) }}" class="name-link">{{ $user->name }}</a>
                            </div>
                        @endif
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
                            <form method="POST" action="{{ route('users.restore', $user->id) }}" class="d-inline"
                                  data-confirm="Restore {{ $user->name }}? The account will appear in the list again."
                                  data-confirm-tone="success">
                                @csrf<button class="btn btn-sm btn-outline-success"><i class="bi bi-arrow-counterclockwise"></i> Restore</button>
                            </form>
                        @else
                            <a href="{{ route('users.edit', $user) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                            {{-- Edit them, what they can do, what they did. --}}
                            <a href="{{ route('users.access', $user) }}" class="btn btn-sm btn-outline-secondary"
                               aria-label="Access for {{ $user->name }}"><i class="bi bi-key"></i></a>
                            <a href="{{ route('users.history', $user) }}" class="btn btn-sm btn-outline-secondary"
                               aria-label="History for {{ $user->name }}"><i class="bi bi-clock-history"></i></a>
                            <div class="btn-group">
                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown"></button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li>
                                        <form method="POST" action="{{ route('users.reset-password', $user) }}" data-confirm="Reset the password for {{ $user->name }}? They will have to set a new one at their next sign-in.">
                                            @csrf<button class="dropdown-item">Reset password</button>
                                        </form>
                                    </li>
                                    @if ($user->status === 'blocked')
                                        <li><form method="POST" action="{{ route('users.unblock', $user) }}"
                                                  data-confirm="Unblock {{ $user->name }}? They will be able to sign in again."
                                                  data-confirm-tone="success">@csrf<button class="dropdown-item text-success">Unblock</button></form></li>
                                    @else
                                        <li>
                                            {{-- The reason is asked inside the confirmation, not by a
                                                 browser prompt after it. --}}
                                            <form method="POST" action="{{ route('users.block', $user) }}"
                                                  data-confirm="Block {{ $user->name }}? Use this when something is wrong with the account's activity — sign-ins from somewhere they are not, or actions they say were not theirs. They cannot sign in until the block is lifted."
                                                  data-confirm-tone="danger"
                                                  data-confirm-input="Reason for blocking"
                                                  data-confirm-field="reason">
                                                @csrf<input type="hidden" name="reason">
                                                <button class="dropdown-item text-danger">Block</button>
                                            </form>
                                        </li>
                                    @endif
                                    <li>
                                        {{-- Deactivate and Block both stop a sign-in, and the reason
                                             for choosing between them is the only thing that tells
                                             them apart — so it is written into the question rather
                                             than left for the administrator to remember. --}}
                                        <form method="POST" action="{{ route('users.toggle-active', $user) }}"
                                              data-confirm="{{ $user->status === 'inactive'
                                                    ? 'Activate '.$user->name.'? They can sign in again from now on.'
                                                    : 'Deactivate '.$user->name.'? Use this while they are away — on leave, or on detail elsewhere — so nobody can sign in as them in the meantime. Nothing is wrong with the account; activate it when they are back.' }}"
                                              data-confirm-tone="{{ $user->status === 'inactive' ? 'success' : 'danger' }}">@csrf<button class="dropdown-item">{{ $user->status==='inactive'?'Activate':'Deactivate' }}</button></form>
                                    </li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><form method="POST" action="{{ route('users.archive', $user) }}"
                                              data-confirm="Archive {{ $user->name }}? Use this when they have left the LGU — resigned, dismissed or died. The account leaves the list but nothing is deleted: their leave record, their filed CSC Form 6 copies and their employee number all stay, and the account can be restored."
                                              data-confirm-tone="danger">@csrf<button class="dropdown-item text-warning">Archive</button></form></li>
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
</div>
@endsection
