@extends('layouts.app')
@section('title', 'Edit role')
@section('content')

{{--
  Editing one of the five fixed roles. There is no create form: the roles are
  the LGU's structure, and a sixth invented here would hold authority nothing in
  the organisation answers for.

  "Full system access (wildcard)" is not on this page. It satisfies every
  permission check in the application, so one click used to be enough to give
  any role unrestricted access to users, security, settings and every employee's
  leave record. RoleController filters it out of the list and refuses it on
  submission — hiding a control is not access control, and this form can be
  replayed with any permission id in it.

  Permissions read DOWN their module, not across two columns. The old grid
  flowed left, right, left, so the first LEAVE permission sat beside the fourth
  and a reader had to zig-zag to take in a group.
--}}

<x-page-head class="mb-3" :title="'Edit role: '.$role->name"
    :back-url="route('roles.index')" back-label="Roles & Permissions" />

<form method="POST" action="{{ route('roles.update', $role) }}">
    @csrf
    @method('PUT')
    <div class="row g-3">
        <div class="col-md-4">
            <div class="card">
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label" for="role-name">Name</label>
                        <input id="role-name" name="name" class="form-control @error('name') is-invalid @enderror"
                               value="{{ old('name', $role->name) }}" required>
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="role-description">Description</label>
                        <input id="role-description" name="description" class="form-control"
                               value="{{ old('description', $role->description) }}">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="role-parent">Inherit from (parent role)</label>
                        <select id="role-parent" name="parent_id" class="form-select">
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
                    <div class="perm-cols">
                        @foreach ($permissions as $module => $perms)
                            <div class="perm-group">
                                <p class="perm-cap">{{ $module }}</p>
                                @foreach ($perms as $perm)
                                    @php $isInherited = in_array($perm->slug, $inherited ?? []); @endphp
                                    <div class="perm {{ $isInherited ? 'is-locked' : '' }}">
                                        <input class="form-check-input" type="checkbox" name="permissions[]"
                                               value="{{ $perm->id }}" id="p{{ $perm->id }}"
                                               @checked(in_array($perm->id, $assigned) || $isInherited)
                                               @disabled($isInherited)>
                                        <label for="p{{ $perm->id }}">
                                            {{ $perm->name }}
                                            @if ($isInherited)<span class="perm-lock">inherited</span>@endif
                                        </label>
                                    </div>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>
@endsection
