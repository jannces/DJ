<?php

namespace App\Http\Middleware;

use App\Models\SystemSetting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Forces logout after a configurable idle window (FR-A7). */
class SessionTimeout
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()) {
            $idleMinutes = (int) SystemSetting::get('auth.session_idle_minutes', 30);
            $lastSeen = $request->session()->get('last_activity_at');

            if ($lastSeen && now()->timestamp - $lastSeen > $idleMinutes * 60) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return $request->expectsJson()
                    ? response()->json(['message' => 'Session expired.'], 401)
                    : redirect()->route('login')->with('status', 'Your session expired due to inactivity. Please sign in again.');
            }

            $request->session()->put('last_activity_at', now()->timestamp);
        }

        return $next($request);
    }
}
