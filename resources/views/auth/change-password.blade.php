@extends('layouts.app')
@section('title', 'Change password')
@section('content')
<div class="row justify-content-center">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header fw-bold"><i class="bi bi-key me-2"></i>Change password</div>
            <div class="card-body">
                @if (auth()->user()->must_change_password)
                    <div class="alert alert-warning small">For security you must set a new password before continuing.</div>
                @endif
                <form method="POST" action="{{ route('password.change.update') }}">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label" for="current_password">Current password</label>
                        <input id="current_password" type="password" name="current_password" required
                               class="form-control @error('current_password') is-invalid @enderror" autocomplete="current-password">
                        @error('current_password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="password">New password</label>
                        <input id="password" type="password" name="password" required
                               class="form-control @error('password') is-invalid @enderror" autocomplete="new-password">
                        @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">Minimum 12 characters with upper &amp; lowercase letters, a number and a symbol.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="password_confirmation">Confirm new password</label>
                        <input id="password_confirmation" type="password" name="password_confirmation" required class="form-control">
                    </div>
                    <button class="btn btn-lgu" type="submit">Update password</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
