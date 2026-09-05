@extends('layouts.app')
@section('title', 'Access — '.$user->name)
@section('content')

{{--
  Per-permission overrides for one account.

  This used to be a second form stacked under the edit form. Both submitted the
  roles, so whichever button was pressed second decided them — and the edit
  form's own role checkboxes were never read by its controller at all, so the
  only thing that could change a role was the button at the bottom of a card
  about something else.

  It is a page of its own now, beside /edit and /history, which is where this
  system already keeps per-user detail. One form per page, so nothing can
  quietly overwrite what the other one holds.
--}}

<div class="user-form">
    <x-page-head class="mb-3" title="Access for {{ $user->name }}"
        :sub="$user->roles->pluck('name')->implode(', ')"
        :back-url="route('users.edit', $user)" back-label="Edit user" />

    @if ($errors->any())
        <div class="alert alert-danger" role="alert">
            <strong>{{ $errors->count() }} {{ Str::plural('problem', $errors->count()) }} to fix.</strong>
            <ul class="mb-0 mt-1 ps-3">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('users.access.update', $user) }}">
        @csrf

        <div class="card">
            <div class="card-header fw-semibold">
                <span>Overrides</span>
                <span class="card-note">a deny wins over any role allow</span>
            </div>
            <div class="card-body">
                {{-- Two columns: thirty-five rows in one column is a scroll, and
                     the module headings stop being the thing you scan for. --}}
                <div class="perm-columns">
                    @foreach ($permissions as $module => $perms)
                        <div class="perm-module">
                            <div class="perm-module-name">{{ $module }}</div>
                            @foreach ($perms as $perm)
                                <div class="perm-row">
                                    <span class="perm-name">{{ $perm->name }}</span>
                                    <label class="perm-allow">
                                        <input type="checkbox" name="allow[]" value="{{ $perm->id }}"
                                               @checked(in_array($perm->id, old('allow', $directAllow)))> allow
                                    </label>
                                    <label class="perm-deny">
                                        <input type="checkbox" name="deny[]" value="{{ $perm->id }}"
                                               @checked(in_array($perm->id, old('deny', $directDeny)))> deny
                                    </label>
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>

                {{-- The default has no control of its own to show it, so it is
                     said instead: two empty boxes is not "undecided". --}}
                <p class="perm-note">
                    Leave both unticked to inherit that permission from the role.
                </p>
            </div>
        </div>

        <div class="form-actions">
            <a href="{{ route('users.edit', $user) }}" class="btn btn-outline-secondary">Cancel</a>
            <button class="btn btn-lgu" type="submit">
                <i class="bi bi-check2 me-1"></i>Save access
            </button>
        </div>
    </form>
</div>

@endsection
