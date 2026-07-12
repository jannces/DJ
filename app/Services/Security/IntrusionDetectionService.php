<?php

namespace App\Services\Security;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IntrusionDetectionService
{
    /** Returns a Response to short-circuit the request, or null to continue. */
    public function inspect(Request $request): ?Response
    {
        return null; // Full implementation lands in Phase 8.
    }
}
