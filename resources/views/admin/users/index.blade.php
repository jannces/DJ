@extends('layouts.app')
@section('title', 'Users')
@section('content')
{{--
  The plain heading the Employees list uses, not the shared
  .list-head/.list-actions pair.

  Those two put the title on one row and the add button on another, which
  left an empty band between the heading and the card. This page is the
  administrator's view of the same people HR sees on Employees, and the two
  were asked to read as one screen; Employees carries a bare `h1` and goes
  straight into its card, so this does too.

  New user still stays a page rather than a panel -- twenty-one fields across
  four sections is not something to read through a modal -- and it keeps its
  place at the right by riding on the heading row instead of a band of its
  own. Departments, Positions, Holidays, Devices and Blocked IPs are NOT
  changed with it: they keep the shared pattern, and this page is the
  deliberate exception because of the list it is being matched to.
--}}
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
    <h1 class="h4 mb-0">User Accounts</h1>
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
                    {{-- The same row the employee list and the rankings draw.
                         An archived account has no edit page to open, so it
                         keeps a plain name rather than a link that refuses. --}}
                    <td>
                        <x-person :name="$user->name" :sub="$user->email"
                            :url="$user->trashed() ? null : route('users.edit', $user)" />
                    </td>
                    <td>@foreach ($user->roles as $r)<span class="badge bg-secondary">{{ $r->name }}</span> @endforeach</td>
                    <td>{{ $user->employeeProfile?->department?->name ?? '—' }}</td>
                    <td>
                        @php $color = ['active'=>'success','inactive'=>'secondary','blocked'=>'danger'][$user->status] ?? 'secondary'; @endphp
                        <span class="badge bg-{{ $color }}">{{ $user->status }}</span>
                    </td>
                    <td class="text-end">
                        @if ($user->trashed())
                            <form method="POST" action="{{ route('users.restore', $user->id) }}" class="d-inline"
                                  data-confirm="Restore {{ $user->name }}? The account will appear in the list again."
                                  data-confirm-tone="success">
                                @csrf<button class="btn btn-sm btn-outline-success"><i class="bi bi-arrow-counterclockwise"></i> Restore</button>
                            </form>
                        @else
                            {{--
                              One labelled button and one menu, which is the row
                              the Employees list draws.

                              It was four controls: a pencil, a key, a clock and
                              a bare caret, none of them carrying a word. Beside
                              the employee list -- same component for the person,
                              same table, same card -- the two pages did not look
                              like the same system, and the icon rank was the
                              reason. It also meant three of a row's four actions
                              were identifiable only by guessing at a glyph.

                              Nothing is lost: Access and History are the same
                              destinations, now named, in the menu that already
                              held Reset password, Block and Archive.
                            --}}
                            <a href="{{ route('users.edit', $user) }}" class="btn btn-sm btn-outline-secondary">Edit</a>
                            <div class="btn-group">
                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle"
                                        data-bs-toggle="dropdown" aria-expanded="false">
                                    <span class="visually-hidden">More actions for {{ $user->name }}</span>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><a class="dropdown-item" href="{{ route('users.access', $user) }}">Access &amp; permissions</a></li>
                                    <li><a class="dropdown-item" href="{{ route('users.history', $user) }}">Account history</a></li>
                                    <li><hr class="dropdown-divider"></li>
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
