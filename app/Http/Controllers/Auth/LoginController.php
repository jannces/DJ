<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\LoginSecurityService;
use App\Services\Auth\OtpService;
use App\Services\Security\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function __construct(
        private readonly LoginSecurityService $security,
        private readonly OtpService $otp,
        private readonly AuditLogger $audit,
    ) {
    }

    public function show(): View|RedirectResponse
    {
        return Auth::check() ? redirect()->route('dashboard') : view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'remember' => ['nullable', 'boolean'],
        ]);

        // Layered throttle on top of the account lockout (STRIDE: DoS).
        $throttleKey = strtolower($credentials['identifier']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            return back()->withErrors([
                'identifier' => 'Too many attempts. Try again in '.RateLimiter::availableIn($throttleKey).' seconds.',
            ]);
        }
        RateLimiter::hit($throttleKey, 60);

        $field = filter_var($credentials['identifier'], FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
        $user = User::where($field, $credentials['identifier'])->first();

        if ($user) {
            $this->security->liftExpiredBlock($user);
            $user->refresh();
        }

        if (! $user) {
            $this->security->recordFailure($request, $credentials['identifier'], null, 'unknown_user');

            return back()->withErrors(['identifier' => 'These credentials do not match our records.'])->onlyInput('identifier');
        }

        if ($user->isBlocked()) {
            $this->security->recordFailure($request, $credentials['identifier'], null, 'blocked');

            return back()->withErrors([
                'identifier' => 'This account is blocked until '.$user->blocked_until?->format('M d, Y h:i A').'. Contact the System Administrator.',
            ])->onlyInput('identifier');
        }

        if ($user->status === User::STATUS_INACTIVE) {
            $this->security->recordFailure($request, $credentials['identifier'], null, 'inactive');

            return back()->withErrors(['identifier' => 'This account is deactivated.'])->onlyInput('identifier');
        }

        if (! Hash::check($credentials['password'], $user->password)) {
            $this->security->recordFailure($request, $credentials['identifier'], $user, 'invalid_password');
            $remaining = max(0, $this->security->maxAttempts() - $user->refresh()->failed_attempts);
            $message = $user->isBlocked()
                ? 'Account blocked for 24 hours after repeated failures. Contact the System Administrator.'
                : "Invalid password. {$remaining} attempt(s) remaining before the account is blocked.";

            return back()->withErrors(['identifier' => $message])->onlyInput('identifier');
        }

        Auth::login($user, (bool) ($credentials['remember'] ?? false));
        $request->session()->regenerate(); // session fixation defense
        RateLimiter::clear($throttleKey);
        $this->security->recordSuccess($request, $user);

        if ($this->otp->enabled()) {
            $request->session()->put('otp_verified', false);
            $this->otp->issue($user);

            return redirect()->route('otp.show')->with('status', 'We emailed you a one-time password.');
        }

        $request->session()->put('otp_verified', true);

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        if ($user = $request->user()) {
            $this->audit->log('logout', $user, [], [], $user);
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'You have been signed out.');
    }
}
