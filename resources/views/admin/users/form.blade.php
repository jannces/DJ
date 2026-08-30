@extends('layouts.app')
@section('title', $user->exists ? 'Edit user' : 'New user')
@section('content')

{{--
  Create / edit a user account.

  Two things this page is careful about:

  · Every field the CSC Form 6 header is built from is required. The form the
    employee later files prints department, position, salary and hire date
    straight onto the sheet, so a blank here is not noticed until somebody is
    holding the paper. Required is marked in the label, enforced by the browser
    for immediate feedback, and enforced again in UserController — the browser
    check is a convenience, the server check is the rule.

  · The role list offers five roles and the controller accepts only those five.
    Department Head is organisational structure with no permissions of its own;
    Super Admin is the unrestricted owner, set up once at install. Neither is
    something to hand out from a form, and neither is accepted even if the
    submission names it.
--}}

@php
    $p = $user->employeeProfile;
    $creating = ! $user->exists;

    // Only the roles this form can show. A role the account already holds that
    // is not in the list is preserved by the controller, not resubmitted here.
    $shownRoleIds = $roles->pluck('id')->all();
    $checkedRoles = array_values(array_intersect($assignedRoles, $shownRoleIds));

    // Whether a role is chosen as the page renders -- after a rejected
    // submission that is what was typed, not what is stored.
    $rolesChosen = ! empty(old('roles', $checkedRoles));
@endphp

<div class="user-form">
    <x-page-head class="mb-3" :title="$creating ? 'Create user' : 'Edit user: '.$user->name"
        :sub="$creating ? 'A temporary password is generated and shown after saving.' : $user->email"
        :back-url="route('users.index')" back-label="Users" />

    @if ($errors->any())
        <div class="alert alert-danger" role="alert">
            <strong>{{ $errors->count() }} {{ Str::plural('field', $errors->count()) }} need attention.</strong>
            <ul class="mb-0 mt-1 ps-3">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- The button is switched off until a role is chosen. This is the
         affordance, not the rule: roles are required in UserController and a
         submission without one is rejected there whatever the browser did. The
         button is therefore NOT rendered disabled -- with the script off it
         stays pressable and the server explains itself, which is better than a
         dead button and no reason for it. --}}
    <form method="POST" action="{{ $creating ? route('users.store') : route('users.update', $user) }}"
          data-requires-checked="roles[]">
        @csrf
        @unless ($creating) @method('PUT') @endunless

        <div class="user-form-grid">
            <div class="user-form-main">

                <div class="card">
                    <div class="card-header fw-semibold">Account</div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="f-name">Full name <span class="req">*</span></label>
                                <input id="f-name" name="name" class="form-control @error('name') is-invalid @enderror"
                                       value="{{ old('name', $user->name) }}" maxlength="255" required>
                                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="f-email">Email <span class="req">*</span></label>
                                <input id="f-email" name="email" type="email"
                                       class="form-control @error('email') is-invalid @enderror"
                                       value="{{ old('email', $user->email) }}" maxlength="255" required>
                                @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            @if ($creating)
                                <div class="col-md-6">
                                    <label class="form-label" for="f-username">Username <span class="req">*</span></label>
                                    <input id="f-username" name="username"
                                           class="form-control @error('username') is-invalid @enderror"
                                           value="{{ old('username') }}" minlength="3" maxlength="255"
                                           pattern="[A-Za-z0-9_\-]+" required>
                                    <div class="form-text">Letters, numbers, dashes and underscores.</div>
                                    @error('username')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="f-empno">Employee no. <span class="req">*</span></label>
                                    {{-- Shown, not asked for. The number is
                                         issued by the server, which ignores
                                         whatever the form sends -- readonly is
                                         a hint to the browser, not a rule.
                                         Left in the markup rather than removed
                                         so the person adding the account can
                                         see what it will be. --}}
                                    <input id="f-empno"
                                           class="form-control"
                                           value="{{ $suggestedEmployeeNo ?? '' }}" readonly>
                                    <div class="form-text">Issued automatically. Never reused, even after an account is archived.</div>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header fw-semibold">Personal details</div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label" for="f-first">First name <span class="req">*</span></label>
                                <input id="f-first" name="first_name"
                                       class="form-control @error('first_name') is-invalid @enderror"
                                       value="{{ old('first_name', $p?->first_name) }}" maxlength="100" required>
                                @error('first_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="f-middle">Middle name</label>
                                <input id="f-middle" name="middle_name"
                                       class="form-control @error('middle_name') is-invalid @enderror"
                                       value="{{ old('middle_name', $p?->middle_name) }}" maxlength="100">
                                <div class="form-text">Leave blank if none.</div>
                                @error('middle_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="f-last">Last name <span class="req">*</span></label>
                                <input id="f-last" name="last_name"
                                       class="form-control @error('last_name') is-invalid @enderror"
                                       value="{{ old('last_name', $p?->last_name) }}" maxlength="100" required>
                                @error('last_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            <div class="col-md-4">
                                <label class="form-label" for="f-gender">Gender <span class="req">*</span></label>
                                <select id="f-gender" name="gender"
                                        class="form-select @error('gender') is-invalid @enderror" required>
                                    <option value="">Select…</option>
                                    @foreach (['male' => 'Male', 'female' => 'Female'] as $value => $label)
                                        <option value="{{ $value }}" @selected(old('gender', $p?->gender) === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                @error('gender')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="f-civil">Civil status <span class="req">*</span></label>
                                <select id="f-civil" name="civil_status"
                                        class="form-select @error('civil_status') is-invalid @enderror" required>
                                    <option value="">Select…</option>
                                    @foreach (\App\Http\Controllers\Admin\UserController::CIVIL_STATUSES as $value)
                                        <option value="{{ $value }}" @selected(old('civil_status', $p?->civil_status) === $value)>{{ ucfirst($value) }}</option>
                                    @endforeach
                                </select>
                                @error('civil_status')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="f-birth">Birth date <span class="req">*</span></label>
                                {{-- A native picker opens at its max when the
                                     field is empty, so with only a max this
                                     one opened fifteen years ago and looked
                                     stale. A min bounds the range as well, so
                                     the year selector offers the years an
                                     employee could actually have been born in
                                     rather than an unbounded spinner.

                                     A century back, not a working lifetime: a
                                     browser bound tighter than the server's
                                     rule would silently refuse something the
                                     server would have accepted, and the
                                     browser check is the convenience here, not
                                     the rule. --}}
                                <input id="f-birth" type="date" name="birth_date"
                                       class="form-control @error('birth_date') is-invalid @enderror"
                                       value="{{ old('birth_date', $p?->birth_date?->toDateString()) }}"
                                       min="{{ now()->subYears(100)->toDateString() }}"
                                       max="{{ now()->subYears(15)->toDateString() }}" required>
                                @error('birth_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                <div class="form-text">Must be 15 or older.</div>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label" for="f-contact">Contact no. <span class="req">*</span></label>
                                <input id="f-contact" name="contact_no"
                                       class="form-control @error('contact_no') is-invalid @enderror"
                                       value="{{ old('contact_no', $p?->contact_no) }}"
                                       pattern="[0-9+()\-\s]{7,30}" maxlength="30" required>
                                <div class="form-text">e.g. 0917 123 4567</div>
                                @error('contact_no')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-8">
                                <label class="form-label" for="f-address">Address (residence) <span class="req">*</span></label>
                                <input id="f-address" name="address"
                                       class="form-control @error('address') is-invalid @enderror"
                                       value="{{ old('address', $p?->address) }}" maxlength="255" required>
                                @error('address')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header fw-semibold">
                        Employment
                        <span class="card-note">printed on every CSC Form 6 this employee files</span>
                    </div>
                    <div class="card-body">
                        {{-- An empty required dropdown with no explanation is a
                             dead end, and it is the state a clean install
                             starts in. Departments and positions are HR's to
                             maintain, so a System Administrator -- who holds
                             users.manage and not departments.manage -- is told
                             who can add them rather than shown a link they
                             cannot follow. --}}
                        @if ($departments->isEmpty() || $positions->isEmpty())
                            @php
                                $missing = collect([
                                    $departments->isEmpty() ? 'department' : null,
                                    $positions->isEmpty() ? 'position' : null,
                                ])->filter();
                            @endphp
                            <div class="alert alert-warning small" role="alert">
                                <strong>No {{ $missing->map(fn ($m) => Str::plural($m))->join(' or ') }} to choose from.</strong>
                                Every account has to name {{ $missing->count() > 1 ? 'both' : 'one' }},
                                so this form cannot be completed until
                                {{ $missing->count() > 1 ? 'they are' : 'it is' }} added.
                                @can('departments.manage')
                                    @if ($departments->isEmpty())
                                        <a href="{{ route('departments.index') }}">Add a department</a>@if ($positions->isEmpty()), @endif
                                    @endif
                                    @if ($positions->isEmpty())
                                        <a href="{{ route('positions.index') }}">add a position</a>
                                    @endif
                                @else
                                    Ask HR to add {{ $missing->count() > 1 ? 'them' : 'it' }} first.
                                @endcan
                            </div>
                        @endif

                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label" for="f-dept">Department <span class="req">*</span></label>
                                <select id="f-dept" name="department_id"
                                        class="form-select @error('department_id') is-invalid @enderror" required>
                                    <option value="">Select…</option>
                                    @foreach ($departments as $d)
                                        <option value="{{ $d->id }}" @selected((int) old('department_id', $p?->department_id) === $d->id)>{{ $d->name }}</option>
                                    @endforeach
                                </select>
                                @error('department_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="f-position">Position <span class="req">*</span></label>
                                <select id="f-position" name="position_id"
                                        class="form-select @error('position_id') is-invalid @enderror" required>
                                    <option value="">Select…</option>
                                    @foreach ($positions as $pos)
                                        <option value="{{ $pos->id }}" @selected((int) old('position_id', $p?->position_id) === $pos->id)>{{ $pos->title }}</option>
                                    @endforeach
                                </select>
                                @error('position_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            <div class="col-md-4">
                                <label class="form-label" for="f-empstatus">Employment status <span class="req">*</span></label>
                                <select id="f-empstatus" name="employment_status"
                                        class="form-select @error('employment_status') is-invalid @enderror" required>
                                    @foreach (\App\Http\Controllers\Admin\UserController::EMPLOYMENT_STATUSES as $value)
                                        <option value="{{ $value }}" @selected(old('employment_status', $p?->employment_status ?? 'permanent') === $value)>{{ ucfirst($value) }}</option>
                                    @endforeach
                                </select>
                                @error('employment_status')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="f-salary">Monthly salary <span class="req">*</span></label>
                                <div class="input-group has-validation">
                                    <span class="input-group-text">₱</span>
                                    <input id="f-salary" type="number" step="0.01" min="0" max="9999999.99"
                                           name="salary" class="form-control @error('salary') is-invalid @enderror"
                                           value="{{ old('salary', $p?->salary) }}" required>
                                    @error('salary')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="f-hired">Date hired <span class="req">*</span></label>
                                <input id="f-hired" type="date" name="date_hired"
                                       class="form-control @error('date_hired') is-invalid @enderror"
                                       value="{{ old('date_hired', $p?->date_hired?->toDateString()) }}"
                                       min="{{ now()->subYears(100)->toDateString() }}"
                                       max="{{ now()->toDateString() }}" required>
                                @error('date_hired')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                <div class="form-text">Not in the future.</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <aside class="user-form-side">
                <div class="card">
                    <div class="card-header fw-semibold">
                        <span>Roles <span class="req">*</span></span>
                        <span class="card-note">choose at least one</span>
                    </div>
                    <div class="card-body">
                        <div class="role-list @error('roles') is-invalid-group @enderror">
                            @foreach ($roles as $r)
                                <label class="role-option" for="r{{ $r->id }}">
                                    <input class="form-check-input" type="checkbox" name="roles[]"
                                           value="{{ $r->id }}" id="r{{ $r->id }}"
                                           @checked(in_array($r->id, old('roles', $checkedRoles)))>
                                    <span>
                                        <span class="role-name">{{ $r->name }}</span>
                                        @if ($r->description)
                                            <span class="role-desc">{{ $r->description }}</span>
                                        @endif
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        @error('roles')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
                        @error('roles.*')<div class="text-danger small mt-2">{{ $message }}</div>@enderror

                        {{-- Pinned to the foot of the card, so the slack from
                             matching the column beside it falls here rather
                             than dangling under the last role. --}}
                        @if ($user->exists)
                            <div class="role-foot">
                                <p class="mb-2">
                                    Permissions come from the roles above.
                                    @if ($overrides)
                                        <b>{{ $overrides }}</b>
                                        {{ Str::plural('override', $overrides) }} in effect on this account.
                                    @else
                                        No overrides are in effect.
                                    @endif
                                </p>
                                <a href="{{ route('users.access', $user) }}" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-key"></i>Review access
                                </a>
                            </div>
                        @else
                            <div class="role-foot">
                                <p class="mb-0">
                                    Permissions come from the roles. Individual ones can be
                                    overridden once the account exists.
                                </p>
                            </div>
                        @endif
                    </div>
                </div>

            </aside>
        </div>

        {{-- Below both columns and to the right, where a form's commit belongs
             once the columns beside each other have both been read. --}}
        <div class="form-actions">
            {{-- Why the button is off, next to the button rather than up in the
                 card the answer is in. --}}
            <p class="form-actions-hint" data-requires-hint @if ($rolesChosen) hidden @endif>
                Choose at least one role first.
            </p>
            <a href="{{ route('users.index') }}" class="btn btn-outline-secondary">Cancel</a>
            <button class="btn btn-lgu" type="submit">
                <i class="bi bi-check2 me-1"></i>{{ $creating ? 'Create user' : 'Save changes' }}
            </button>
        </div>
    </form>

</div>
@endsection
