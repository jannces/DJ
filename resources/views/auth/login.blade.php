@extends('layouts.guest')
@section('title', 'Sign in')
@section('content')
<h2 class="h6 fw-bold mb-3">Sign in to your account</h2>
<form method="POST" action="{{ route('login') }}">
    @csrf
    <div class="mb-3">
        <label class="form-label" for="identifier">Email or username</label>
        <input id="identifier" name="identifier" type="text" required autofocus autocomplete="username"
               class="form-control @error('identifier') is-invalid @enderror" value="{{ old('identifier') }}">
        @error('identifier')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="mb-3">
        <label class="form-label" for="password">Password</label>
        <input id="password" name="password" type="password" required autocomplete="current-password"
               class="form-control @error('password') is-invalid @enderror">
        @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" name="remember" value="1" id="remember">
            <label class="form-check-label" for="remember">Remember me</label>
        </div>
        <a href="{{ route('password.request') }}" class="small">Forgot password?</a>
    </div>
    <button type="submit" class="btn btn-lgu w-100"><i class="bi bi-box-arrow-in-right me-1"></i>Sign in</button>
</form>
@endsection
