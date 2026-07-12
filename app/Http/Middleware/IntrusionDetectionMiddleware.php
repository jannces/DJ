<?php

namespace App\Http\Middleware;

use App\Services\Security\IntrusionDetectionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Delegates signature + anomaly scanning to IntrusionDetectionService (Phase 8). */
class IntrusionDetectionMiddleware
{
    public function __construct(private readonly IntrusionDetectionService $ids)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($blockResponse = $this->ids->inspect($request)) {
            return $blockResponse;
        }

        return $next($request);
    }
}
