<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\IntrusionLog;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SecurityApiController extends Controller
{
    /** Unseen intrusion count + latest event, polled by the dashboard bell. */
    public function alerts(Request $request): JsonResponse
    {
        if (! $request->user()?->hasPermission('security.dashboard')) {
            return response()->json(['unseen' => 0]);
        }

        $unseen = IntrusionLog::where('handled', false)->count();
        $latest = IntrusionLog::latest('id')->first();

        return response()->json([
            'unseen' => $unseen,
            'latest' => $latest ? [
                'id' => $latest->id,
                'category' => $latest->category,
                'severity' => $latest->severity,
                'ip' => $latest->ip,
                'at' => $latest->created_at->diffForHumans(),
            ] : null,
        ]);
    }

    public function stats(Request $request, DashboardService $dashboard): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('security.dashboard'), 403);

        return response()->json([
            'intrusions_by_day' => $dashboard->intrusionsByDay(),
        ]);
    }
}
