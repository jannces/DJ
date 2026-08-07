@extends('layouts.guest')
@section('title', 'Verify OTP')
@section('content')
<div class="text-center mb-3">
    <div class="mx-auto mb-3 d-flex align-items-center justify-content-center"
         style="width:64px;height:64px;border-radius:18px;background:var(--info-bg);color:var(--info);font-size:1.8rem">
        <i class="bi bi-envelope-check"></i>
    </div>
    <h1 class="a-title">Verify it's you</h1>
    <p class="a-sub">We emailed a 6-digit code to <strong>{{ auth()->user()->email }}</strong>.
        It expires in {{ \App\Models\SystemSetting::get('auth.otp_ttl_minutes', 5) }} minutes.</p>
</div>
<form method="POST" action="{{ route('otp.verify') }}">
    @csrf
    <div class="mb-3">
        <input id="code" name="code" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autofocus
               class="form-control form-control-lg text-center @error('code') is-invalid @enderror"
               style="letter-spacing:.7em;font-size:1.6rem;font-weight:700" placeholder="••••••">
        @error('code')<div class="text-danger small mt-2 text-center">{{ $message }}</div>@enderror
    </div>
    <button type="submit" class="btn btn-lgu w-100 py-2 mb-2">Verify &amp; continue</button>
</form>
<div class="d-flex justify-content-between mt-2">
    <form method="POST" action="{{ route('otp.resend') }}">@csrf<button type="submit" class="btn btn-sm btn-link">Resend code</button></form>
    <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="btn btn-sm btn-link text-muted">Cancel</button></form>
</div>
@endsection
