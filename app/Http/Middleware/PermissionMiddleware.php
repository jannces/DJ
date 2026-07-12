<?php

namespace App\Http\Middleware;

use App\Models\IntrusionLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dynamic RBAC gate: `->middleware('permission:users.manage')`.
 * Slugs resolve against the database via RbacService (never hardcoded).
 * Unauthorized hits are recorded as privilege-escalation probes.
 */
class PermissionMiddleware
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->guest(route('login'));
        }

        foreach ($permissions as $permission) {
            if ($user->hasPermission($permission)) {
                return $next($request);
            }
        }

        IntrusionLog::create([
            'category' => 'privilege',
            'severity' => 'medium',
            'route' => $request->path(),
            'method' => $request->method(),
            'payload_excerpt' => 'Denied permission(s): '.implode(',', $permissions),
            'matched_rule' => 'rbac_denied',
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'user_id' => $user->id,
        ]);

        abort(403);
    }
}
