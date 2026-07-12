<?php

namespace App\Http\Middleware;

use App\Models\BlockedIp;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/** First line of the kernel: reject blocked IPs cheaply (DoS containment). */
class BlockedIpMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $ip = $request->ip();

        $blocked = Cache::remember("blocked-ip.{$ip}", 60, function () use ($ip) {
            return BlockedIp::currentlyActive()->where('ip', $ip)->exists();
        });

        if ($blocked) {
            return response()->view('errors.blocked', ['ip' => $ip], 403);
        }

        return $next($request);
    }
}
