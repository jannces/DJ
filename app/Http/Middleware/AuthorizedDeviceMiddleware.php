<?php

namespace App\Http\Middleware;

use App\Models\AuthorizedDevice;
use App\Models\IntrusionLog;
use App\Models\SystemSetting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * LAN device allow-list (ADR-006). When enforcement is on, only IPs
 * registered and active in authorized_devices are served. Loopback is
 * seeded so administrators can never be locked out.
 */
class AuthorizedDeviceMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! SystemSetting::get('security.device_enforcement', false)) {
            return $next($request);
        }

        $ip = $request->ip();
        $device = Cache::remember("device.{$ip}", 60, function () use ($ip) {
            return AuthorizedDevice::active()->where('ip_address', $ip)->first() ?: false;
        });

        if ($device === false) {
            IntrusionLog::create([
                'category' => 'device',
                'severity' => 'high',
                'route' => $request->path(),
                'method' => $request->method(),
                'payload_excerpt' => "Unauthorized device attempted access from {$ip}",
                'matched_rule' => 'device_not_registered',
                'ip' => $ip,
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
                'user_id' => $request->user()?->id,
            ]);

            return response()->view('errors.device-unauthorized', [], 403);
        }

        // Throttled last-active heartbeat (drives online/offline on the dashboard).
        if (! Cache::has("device.seen.{$ip}")) {
            AuthorizedDevice::where('ip_address', $ip)->update(['last_active_at' => now()]);
            Cache::put("device.seen.{$ip}", true, 60);
        }

        return $next($request);
    }
}
