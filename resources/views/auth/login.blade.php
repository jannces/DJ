@extends('layouts.guest')
@section('title', 'Sign in')
@section('content')
<div class="d-lg-none text-center mb-4">
    <div class="a-seal mx-auto" style="width:52px;height:52px;font-size:1.5rem"><i class="bi bi-buildings"></i></div>
    <div class="mt-2 fw-bold">LGU Alicia</div>
</div>
<h1 class="a-title">Welcome back</h1>
<p class="a-sub">Sign in to continue to the Leave Management System.</p>

<form method="POST" action="{{ route('login') }}">
    @csrf
    <div class="mb-3">
        <label class="form-label" for="identifier">Email or username</label>
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-person"></i></span>
            <input id="identifier" name="identifier" type="text" required autofocus autocomplete="username"
                   class="form-control @error('identifier') is-invalid @enderror" value="{{ old('identifier') }}"
                   placeholder="you@alicia.gov.ph">
        </div>
        @error('identifier')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
    </div>
    <div class="mb-3">
        <label class="form-label d-flex justify-content-between" for="password">
            <span>Password</span>
            <a href="{{ route('password.request') }}" class="small fw-normal">Forgot password?</a>
        </label>
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-lock"></i></span>
            <input id="password" name="password" type="password" required autocomplete="current-password"
                   class="form-control @error('password') is-invalid @enderror" placeholder="••••••••••••">
            <button class="btn btn-outline-secondary" type="button" onclick="const p=document.getElementById('password');p.type=p.type==='password'?'text':'password';this.querySelector('i').className=p.type==='password'?'bi bi-eye':'bi bi-eye-slash'" tabindex="-1"><i class="bi bi-eye"></i></button>
        </div>
        @error('password')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
    </div>
    <div class="form-check mb-4">
        <input class="form-check-input" type="checkbox" name="remember" value="1" id="remember">
        <label class="form-check-label small" for="remember">Keep me signed in on this device</label>
    </div>
    <button type="submit" class="btn btn-lgu w-100 py-2"><i class="bi bi-box-arrow-in-right"></i>Sign in</button>
</form>

<div class="text-center mt-4">
    <button class="btn btn-sm btn-link text-muted" onclick="const n=document.documentElement.getAttribute('data-bs-theme')==='dark'?'light':'dark';document.documentElement.setAttribute('data-bs-theme',n);localStorage.setItem('lms-theme',n)">
        <i class="bi bi-circle-half"></i> Toggle theme
    </button>
</div>
@endsection
