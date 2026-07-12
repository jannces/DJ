@extends('layouts.guest')
@section('title', 'Verify OTP')
@section('content')
<h2 class="h6 fw-bold mb-1">Two-factor verification</h2>
<p class="text-muted small">We emailed a 6-digit one-time password to
    <strong>{{ auth()->user()->email }}</strong>. It expires in
    {{ \App\Models\SystemSetting::get('auth.otp_ttl_minutes', 5) }} minutes.</p>
<form method="POST" action="{{ route('otp.verify') }}">
    @csrf
    <div class="mb-3">
        <label class="form-label" for="code">One-time password</label>
        <input id="code" name="code" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autofocus
               class="form-control form-control-lg text-center @error('code') is-invalid @enderror"
               style="letter-spacing:.6em" placeholder="••••••">
        @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <button type="submit" class="btn btn-lgu w-100 mb-2">Verify</button>
</form>
<form method="POST" action="{{ route('otp.resend') }}" class="text-center">
    @csrf
    <button type="submit" class="btn btn-link btn-sm">Resend code</button>
</form>
<form method="POST" action="{{ route('logout') }}" class="text-center">
    @csrf
    <button type="submit" class="btn btn-link btn-sm text-muted">Cancel and sign out</button>
</form>
@endsection
