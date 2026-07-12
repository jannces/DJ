<?php

namespace App\Http\Middleware;

use App\Models\ActivityLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Page-level activity trail for authenticated users (STRIDE: Repudiation). */
class ActivityLogMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Log meaningful navigations only (skip polling/asset/HEAD noise).
        if ($request->user()
            && ! $request->isMethod('HEAD')
            && ! $request->routeIs('api.security.alerts')
            && ! str_contains((string) $request->path(), 'vendor/')) {
            ActivityLog::create([
                'user_id' => $request->user()->id,
                'method' => $request->method(),
                'path' => substr($request->path(), 0, 255),
                'route_name' => $request->route()?->getName(),
                'ip' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
            ]);
        }

        return $response;
    }
}
