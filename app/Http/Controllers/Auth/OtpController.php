<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\OtpService;
use App\Services\Security\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

class OtpController extends Controller
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly AuditLogger $audit,
    ) {
    }

    public function show(Request $request): View|RedirectResponse
    {
        if ($request->session()->get('otp_verified', false) || ! $this->otp->enabled()) {
            return redirect()->route('dashboard');
        }

        return view('auth.otp');
    }

    public function verify(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'digits:6']]);

        $key = 'otp-verify|'.$request->user()->id;
        if (RateLimiter::tooManyAttempts($key, 10)) {
            return back()->withErrors(['code' => 'Too many attempts. Wait a minute and try again.']);
        }
        RateLimiter::hit($key, 60);

        if (! $this->otp->verify($request->user(), $request->string('code'))) {
            $this->audit->log('otp_failed', $request->user());

            return back()->withErrors(['code' => 'Invalid or expired code.']);
        }

        $request->session()->put('otp_verified', true);
        $request->session()->regenerate();
        $this->audit->log('otp_verified', $request->user());

        return redirect()->intended(route('dashboard'));
    }

    public function resend(Request $request): RedirectResponse
    {
        $key = 'otp-resend|'.$request->user()->id;
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return back()->withErrors(['code' => 'Please wait before requesting another code.']);
        }
        RateLimiter::hit($key, 120);

        $this->otp->issue($request->user());

        return back()->with('status', 'A new code is on its way to your inbox.');
    }
}
