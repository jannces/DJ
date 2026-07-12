@extends('layouts.app')
@section('title', $role->exists ? 'Edit role' : 'New role')
@section('content')
<h1 class="h4 mb-3">{{ $role->exists ? 'Edit role: '.$role->name : 'Create role' }}</h1>
<form method="POST" action="{{ $role->exists ? route('roles.update', $role) : route('roles.store') }}">
    @csrf
    @if ($role->exists) @method('PUT') @endif
    <div class="row g-3">
        <div class="col-md-4">
            <div class="card">
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Name</label>
                        <input name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $role->name) }}" required>
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    @unless ($role->exists)
                        <div class="mb-3">
                            <label class="form-label">Slug</label>
                            <input name="slug" class="form-control @error('slug') is-invalid @enderror" value="{{ old('slug') }}" required>
                            @error('slug')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    @endunless
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <input name="description" class="form-control" value="{{ old('description', $role->description) }}">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Inherit from (parent role)</label>
                        <select name="parent_id" class="form-select">
                            <option value="">— none —</option>
                            @foreach ($roles as $r)
                                <option value="{{ $r->id }}" @selected(old('parent_id', $role->parent_id) == $r->id)>{{ $r->name }}</option>
                            @endforeach
                        </select>
                        <div class="form-text">Inherited permissions are granted automatically and shown locked below.</div>
                    </div>
                    <button class="btn btn-lgu w-100" type="submit">Save role</button>
                </div>
            </div>
        </div>
        <div class="col-md-8">
            <div class="card">
                <div class="card-header fw-semibold">Permissions</div>
                <div class="card-body">
                    @foreach ($permissions as $module => $perms)
                        <div class="mb-3">
                            <div class="text-uppercase small text-muted mb-1">{{ $module }}</div>
                            <div class="row">
                                @foreach ($perms as $perm)
                                    @php $isInherited = in_array($perm->slug, $inherited ?? []); @endphp
                                    <div class="col-md-6">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="permissions[]"
                                                   value="{{ $perm->id }}" id="p{{ $perm->id }}"
                                                   @checked(in_array($perm->id, $assigned) || $isInherited)
                                                   @disabled($isInherited)>
                                            <label class="form-check-label small" for="p{{ $perm->id }}">
                                                {{ $perm->name }}
                                                @if ($isInherited)<span class="badge bg-light text-muted">inherited</span>@endif
                                            </label>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</form>
@endsection
