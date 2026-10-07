@extends('layouts.guest')
@section('title', 'Sign in')
@section('content')
<h1 class="auth-title">Welcome back</h1>
<p class="auth-sub">Sign in to continue to the Leave Management System.</p>

{{-- The controller already says how many attempts remain, when a block lifts
     and how long a throttle has left. Under a field at 12px nobody reads it. --}}
@if ($errors->any())
    <div class="auth-note auth-note-bad" role="alert" id="auth-alert">
        <i class="bi bi-exclamation-circle" aria-hidden="true"></i>
        @if ($errors->count() === 1)
            <span>{{ $errors->first() }}</span>
        @else
            <ul>@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
        @endif
    </div>
@endif

<form method="POST" action="{{ route('login') }}" novalidate>
    @csrf

    <div class="auth-field">
        <label for="identifier">Email or username</label>
        <div class="auth-input @error('identifier') is-bad @enderror">
            <i class="bi bi-person" aria-hidden="true"></i>
            <input id="identifier" name="identifier" type="text" required autofocus
                   autocomplete="username" value="{{ old('identifier') }}"
                   placeholder="Email or username"
                   @error('identifier') aria-invalid="true" aria-describedby="auth-alert" @enderror>
        </div>
    </div>

    <div class="auth-field">
        <label for="password">
            <span>Password</span>
            <a href="{{ route('password.request') }}">Forgot password?</a>
        </label>
        <div class="auth-input @error('password') is-bad @enderror">
            <i class="bi bi-lock" aria-hidden="true"></i>
            <input id="password" name="password" type="password" required
                   autocomplete="current-password" placeholder="••••••••••••"
                   @error('password') aria-invalid="true" aria-describedby="auth-alert" @enderror>
            {{-- Reachable by keyboard and named, so a screen reader does not
                 announce it as an unlabelled button. --}}
            <button type="button" class="auth-eye" aria-label="Show password" aria-pressed="false"
                    onclick="const p=document.getElementById('password'),s=p.type==='password';p.type=s?'text':'password';this.setAttribute('aria-label',s?'Hide password':'Show password');this.setAttribute('aria-pressed',s?'true':'false');this.querySelector('i').className=s?'bi bi-eye-slash':'bi bi-eye'">
                <i class="bi bi-eye" aria-hidden="true"></i>
            </button>
        </div>
    </div>

    <button type="submit" class="auth-cta">
        <i class="bi bi-box-arrow-in-right" aria-hidden="true"></i>Sign in
    </button>
</form>

<div class="auth-rule"></div>

<p class="auth-prot">
    <i class="bi bi-shield-check" aria-hidden="true"></i>
    <span>Protected by two-factor authentication and intrusion detection.</span>
</p>
@endsection
