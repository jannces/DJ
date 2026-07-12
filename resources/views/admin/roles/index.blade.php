@extends('layouts.app')
@section('title', 'Roles & Permissions')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Roles &amp; Permissions</h1>
    <a href="{{ route('roles.create') }}" class="btn btn-lgu btn-sm"><i class="bi bi-plus-lg me-1"></i>New role</a>
</div>
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Role</th><th>Inherits</th><th>Permissions</th><th>Users</th><th></th></tr></thead>
            <tbody>
            @foreach ($roles as $role)
                <tr>
                    <td>
                        <div class="fw-semibold">{{ $role->name }}</div>
                        <div class="text-muted small">{{ $role->slug }} @if($role->is_system)<span class="badge bg-secondary">system</span>@endif</div>
                    </td>
                    <td>{{ $role->parent?->name ?? '—' }}</td>
                    <td>{{ $role->permissions_count }}</td>
                    <td>{{ $role->users_count }}</td>
                    <td class="text-end">
                        <a href="{{ route('roles.edit', $role) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                        @unless ($role->is_system)
                            <form method="POST" action="{{ route('roles.destroy', $role) }}" class="d-inline" data-confirm="Delete role {{ $role->name }}?">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        @endunless
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
