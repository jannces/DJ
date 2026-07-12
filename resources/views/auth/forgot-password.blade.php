@extends('layouts.guest')
@section('title', 'Forgot password')
@section('content')
<h2 class="h6 fw-bold mb-1">Reset your password</h2>
<p class="text-muted small">Enter your registered email and we will send a reset link.</p>
<form method="POST" action="{{ route('password.email') }}">
    @csrf
    <div class="mb-3">
        <label class="form-label" for="email">Email address</label>
        <input id="email" name="email" type="email" required autofocus
               class="form-control @error('email') is-invalid @enderror" value="{{ old('email') }}">
        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <button type="submit" class="btn btn-lgu w-100 mb-2">Email reset link</button>
    <a href="{{ route('login') }}" class="btn btn-link btn-sm w-100">Back to sign in</a>
</form>
@endsection
