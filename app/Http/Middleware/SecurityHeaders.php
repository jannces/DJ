<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Hardening response headers (STRIDE: Information Disclosure / Tampering). */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'same-origin',
            'Permissions-Policy' => 'geolocation=(), microphone=(), camera=()',
            'X-XSS-Protection' => '1; mode=block',
            // Self-only CSP; assets are vendored locally (no CDN).
            'Content-Security-Policy' => "default-src 'self'; "
                ."script-src 'self' 'unsafe-inline'; "
                ."style-src 'self' 'unsafe-inline'; "
                ."img-src 'self' data:; font-src 'self' data:; "
                ."connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'",
        ];

        if ($request->secure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        foreach ($headers as $key => $value) {
            $response->headers->set($key, $value);
        }

        return $response;
    }
}
