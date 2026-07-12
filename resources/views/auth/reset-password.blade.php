@extends('layouts.guest')
@section('title', 'Choose a new password')
@section('content')
<h2 class="h6 fw-bold mb-3">Choose a new password</h2>
<form method="POST" action="{{ route('password.update') }}">
    @csrf
    <input type="hidden" name="token" value="{{ $token }}">
    <div class="mb-3">
        <label class="form-label" for="email">Email address</label>
        <input id="email" name="email" type="email" required value="{{ old('email', $email) }}"
               class="form-control @error('email') is-invalid @enderror">
        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="mb-3">
        <label class="form-label" for="password">New password</label>
        <input id="password" name="password" type="password" required autocomplete="new-password"
               class="form-control @error('password') is-invalid @enderror">
        @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
        <div class="form-text">Minimum 12 characters with upper &amp; lowercase letters, a number and a symbol.</div>
    </div>
    <div class="mb-3">
        <label class="form-label" for="password_confirmation">Confirm new password</label>
        <input id="password_confirmation" name="password_confirmation" type="password" required class="form-control">
    </div>
    <button type="submit" class="btn btn-lgu w-100">Update password</button>
</form>
@endsection
