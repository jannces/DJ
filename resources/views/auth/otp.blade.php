@extends('layouts.guest')
@section('title', 'Verify OTP')

@section('content')
<div class="otp-crest" aria-hidden="true"><i class="bi bi-envelope-check"></i></div>
<h1 class="auth-title otp-title">Verify it&rsquo;s you</h1>
<p class="auth-sub otp-lead">
    We emailed a 6-digit code to <b>{{ auth()->user()->email }}</b>.
    It expires in {{ \App\Models\SystemSetting::get('auth.otp_ttl_minutes', 5) }} minutes.
</p>

<form method="POST" action="{{ route('otp.verify') }}">
    @csrf

    {{-- One input is the value; the six cells are a view of it. Paste, autofill,
         auto-advance and backspace stay the browser's job, and the field
         submits as one value. The cells are drawn only once the script has
         run — without it this is a single plain box, which is worse-looking
         and impossible to misalign. --}}
    <div class="otp{{ $errors->has('code') ? ' is-bad' : '' }}">
        <div class="otp-cells" aria-hidden="true">
            <span></span><span></span><span></span><span></span><span></span><span></span>
        </div>
        <input id="code" name="code" type="text" inputmode="numeric" pattern="[0-9]*"
               maxlength="6" required autofocus autocomplete="one-time-code"
               spellcheck="false" aria-label="6-digit code"
               @error('code') aria-invalid="true" aria-describedby="otp-msg" @enderror>
    </div>

    {{-- Exactly one message, and the error wins: a failed verification can
         still carry the status flashed by the resend before it, and "a new code
         is on its way" under "invalid code" reads as though one just arrived. --}}
    @if ($errors->has('code'))
        <div class="auth-note auth-note-bad" role="alert" id="otp-msg">
            <i class="bi bi-exclamation-circle" aria-hidden="true"></i>
            <span>{{ $errors->first('code') }}</span>
        </div>
    @elseif (session('status'))
        <div class="auth-note auth-note-ok" id="otp-msg">
            <i class="bi bi-check-circle" aria-hidden="true"></i>
            <span>{{ session('status') }}</span>
        </div>
    @endif

    <button type="submit" class="auth-cta">Verify &amp; continue</button>
</form>

<div class="otp-foot">
    <form method="POST" action="{{ route('otp.resend') }}">
        @csrf
        {{-- The server refuses a fourth resend inside two minutes, so the
             button is disabled for exactly as long as that refusal lasts. --}}
        <button type="submit" id="otp-resend" data-in="{{ $resendIn }}" @disabled($resendIn > 0)>
            @if ($resendIn > 0)
                Resend in <span class="otp-tick">{{ gmdate('i:s', $resendIn) }}</span>
            @else
                Resend code
            @endif
        </button>
    </form>
    <form method="POST" action="{{ route('logout') }}">
        @csrf<button type="submit" class="otp-cancel">Cancel</button>
    </form>
</div>

<script>
// Entering and submitting a code needs none of this — the field works on its
// own. What this adds is the six-box presentation and the resend countdown.
(function () {
    var input = document.getElementById('code');
    var cells = document.querySelectorAll('.otp-cells span');

    // Only claim the cells once we can actually keep them filled.
    document.documentElement.classList.add('otp-js');

    function paint() {
        var v = input.value, n = v.length;
        for (var i = 0; i < cells.length; i++) {
            cells[i].textContent = v.charAt(i);
            cells[i].toggleAttribute('data-on', i < n);
            cells[i].toggleAttribute('data-at', document.activeElement === input && i === Math.min(n, 5));
        }
    }
    input.addEventListener('input', function () {
        input.value = input.value.replace(/\D/g, '').slice(0, 6);
        paint();
    });
    input.addEventListener('focus', paint);
    input.addEventListener('blur', paint);
    paint();

    var btn = document.getElementById('otp-resend');
    var left = parseInt(btn.dataset.in, 10) || 0;
    if (left > 0) {
        var t = setInterval(function () {
            if (--left <= 0) {
                clearInterval(t);
                btn.disabled = false;
                btn.textContent = 'Resend code';
                return;
            }
            var m = String(Math.floor(left / 60)).padStart(2, '0');
            var s = String(left % 60).padStart(2, '0');
            btn.innerHTML = 'Resend in <span class="otp-tick">' + m + ':' + s + '</span>';
        }, 1000);
    }
})();
</script>
@endsection
