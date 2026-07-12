<?php

namespace App\Http\Middleware;

use App\Services\Auth\OtpService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A session is only fully authenticated once the emailed OTP is verified. */
class EnsureOtpVerified
{
    public function __construct(private readonly OtpService $otp)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()
            && $this->otp->enabled()
            && ! $request->session()->get('otp_verified', false)) {
            return redirect()->route('otp.show');
        }

        return $next($request);
    }
}
