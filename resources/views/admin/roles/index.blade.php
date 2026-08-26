@extends('layouts.app')
@section('title', 'Roles & Permissions')
@section('content')

{{--
  Five roles, fixed by the LGU's structure. There is no "New role" button and
  no delete: a sixth invented here would hold authority nothing in the
  organisation answers for, and all five are system roles that destroy()
  refuses anyway. What this page is for is adjusting what an existing role may
  do.

  The "Inherits" column is gone. Every role but System Administrator inherits
  from Employee, so the column repeated the same word down the page and said
  nothing about the role you were looking at; the parent is on the edit screen,
  where it can be changed.
--}}

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Roles &amp; Permissions</h1>
    <span class="roles-note">{{ $roles->count() }} fixed roles &middot; set by the LGU&rsquo;s structure</span>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 roles-table">
            <thead>
                <tr>
                    <th>Role</th>
                    <th>What it does</th>
                    {{-- Counts are right-aligned, and so are their headings, so
                         the number sits under the word that names it. --}}
                    <th class="num">Permissions</th>
                    <th class="num">Users</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            @foreach ($roles as $role)
                <tr>
                    <td>
                        <div class="fw-semibold">{{ $role->name }}</div>
                        <div class="text-muted small">
                            <code>{{ $role->slug }}</code>
                            @if ($role->is_system)<span class="badge bg-secondary">system</span>@endif
                        </div>
                    </td>
                    <td class="text-muted small role-about">{{ $role->description }}</td>
                    <td class="num">{{ $role->permissions_count }}</td>
                    <td class="num">{{ $role->users_count }}</td>
                    <td class="text-end">
                        <a href="{{ route('roles.edit', $role) }}" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-pencil"></i>
                        </a>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>

@endsection
