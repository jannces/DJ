@extends('layouts.app')
@section('title', $user->exists ? 'Edit user' : 'New user')
@section('content')
<h1 class="h4 mb-3">{{ $user->exists ? 'Edit user: '.$user->name : 'Create user' }}</h1>

<form method="POST" action="{{ $user->exists ? route('users.update', $user) : route('users.store') }}">
    @csrf
    @if ($user->exists) @method('PUT') @endif
    @php $p = $user->employeeProfile; @endphp
    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card mb-3">
                <div class="card-header fw-semibold">Account</div>
                <div class="card-body row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Full name</label>
                        <input name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $user->name) }}" required>
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Email</label>
                        <input name="email" type="email" class="form-control @error('email') is-invalid @enderror" value="{{ old('email', $user->email) }}" required>
                        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    @unless ($user->exists)
                        <div class="col-md-6">
                            <label class="form-label">Username</label>
                            <input name="username" class="form-control @error('username') is-invalid @enderror" value="{{ old('username') }}" required>
                            @error('username')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    @endunless
                </div>
            </div>

            <div class="card">
                <div class="card-header fw-semibold">Employee profile</div>
                <div class="card-body row g-3">
                    @unless ($user->exists)
                        <div class="col-md-4">
                            <label class="form-label">Employee no.</label>
                            <input name="employee_no" class="form-control @error('employee_no') is-invalid @enderror" value="{{ old('employee_no') }}" required>
                            @error('employee_no')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    @endunless
                    <div class="col-md-4"><label class="form-label">First name</label>
                        <input name="first_name" class="form-control" value="{{ old('first_name', $p?->first_name) }}" required></div>
                    <div class="col-md-4"><label class="form-label">Middle name</label>
                        <input name="middle_name" class="form-control" value="{{ old('middle_name', $p?->middle_name) }}"></div>
                    <div class="col-md-4"><label class="form-label">Last name</label>
                        <input name="last_name" class="form-control" value="{{ old('last_name', $p?->last_name) }}" required></div>
                    <div class="col-md-3"><label class="form-label">Gender</label>
                        <select name="gender" class="form-select">
                            <option value="">—</option>
                            <option value="male" @selected(old('gender',$p?->gender)==='male')>Male</option>
                            <option value="female" @selected(old('gender',$p?->gender)==='female')>Female</option>
                        </select></div>
                    <div class="col-md-3"><label class="form-label">Civil status</label>
                        <input name="civil_status" class="form-control" value="{{ old('civil_status', $p?->civil_status) }}"></div>
                    <div class="col-md-3"><label class="form-label">Birth date</label>
                        <input type="date" name="birth_date" class="form-control" value="{{ old('birth_date', $p?->birth_date?->toDateString()) }}"></div>
                    <div class="col-md-3"><label class="form-label">Contact no.</label>
                        <input name="contact_no" class="form-control" value="{{ old('contact_no', $p?->contact_no) }}"></div>
                    <div class="col-12"><label class="form-label">Address (residence)</label>
                        <input name="address" class="form-control" value="{{ old('address', $p?->address) }}"></div>
                    <div class="col-md-4"><label class="form-label">Department</label>
                        <select name="department_id" class="form-select">
                            <option value="">—</option>
                            @foreach ($departments as $d)<option value="{{ $d->id }}" @selected(old('department_id',$p?->department_id)==$d->id)>{{ $d->name }}</option>@endforeach
                        </select></div>
                    <div class="col-md-4"><label class="form-label">Position</label>
                        <select name="position_id" class="form-select">
                            <option value="">—</option>
                            @foreach ($positions as $pos)<option value="{{ $pos->id }}" @selected(old('position_id',$p?->position_id)==$pos->id)>{{ $pos->title }}</option>@endforeach
                        </select></div>
                    <div class="col-md-4"><label class="form-label">Employment status</label>
                        <select name="employment_status" class="form-select">
                            @foreach (['permanent','casual','contractual','coterminous'] as $es)
                                <option value="{{ $es }}" @selected(old('employment_status',$p?->employment_status ?? 'permanent')===$es)>{{ ucfirst($es) }}</option>
                            @endforeach
                        </select></div>
                    <div class="col-md-4"><label class="form-label">Monthly salary</label>
                        <input type="number" step="0.01" name="salary" class="form-control @error('salary') is-invalid @enderror" value="{{ old('salary', $p?->salary ?? 0) }}" required>
                        @error('salary')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                    <div class="col-md-4"><label class="form-label">Date hired</label>
                        <input type="date" name="date_hired" class="form-control" value="{{ old('date_hired', $p?->date_hired?->toDateString()) }}"></div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-header fw-semibold">Roles</div>
                <div class="card-body">
                    @foreach ($roles as $r)
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="roles[]" value="{{ $r->id }}" id="r{{ $r->id }}" @checked(in_array($r->id, $assignedRoles))>
                            <label class="form-check-label" for="r{{ $r->id }}">{{ $r->name }}</label>
                        </div>
                    @endforeach
                    @unless ($user->exists)
                        <p class="text-muted small mt-2 mb-0">A temporary password is generated and shown after saving.</p>
                    @endunless
                </div>
            </div>
            <button class="btn btn-lgu w-100 mt-3" type="submit">Save</button>
        </div>
    </div>
</form>

@if ($user->exists)
    <hr class="my-4">
    <form method="POST" action="{{ route('users.assign-roles', $user) }}">
        @csrf
        <div class="card">
            <div class="card-header fw-semibold">Fine-grained permission overrides</div>
            <div class="card-body">
                <input type="hidden" name="roles[]" value="">
                @foreach ($assignedRoles as $rid)<input type="hidden" name="roles[]" value="{{ $rid }}">@endforeach
                <p class="text-muted small">Grant or explicitly deny individual permissions (deny wins over any role allow).</p>
                @foreach ($permissions as $module => $perms)
                    <div class="mb-2">
                        <div class="text-uppercase small text-muted">{{ $module }}</div>
                        @foreach ($perms as $perm)
                            <div class="d-flex align-items-center gap-3 small py-1">
                                <span class="flex-grow-1">{{ $perm->name }}</span>
                                <label class="text-success"><input type="checkbox" name="allow[]" value="{{ $perm->id }}" @checked(in_array($perm->id, $directAllow))> allow</label>
                                <label class="text-danger"><input type="checkbox" name="deny[]" value="{{ $perm->id }}" @checked(in_array($perm->id, $directDeny))> deny</label>
                            </div>
                        @endforeach
                    </div>
                @endforeach
                <button class="btn btn-lgu btn-sm" type="submit">Update access</button>
            </div>
        </div>
    </form>
@endif
@endsection
