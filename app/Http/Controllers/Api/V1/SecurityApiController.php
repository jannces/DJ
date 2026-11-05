<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\IntrusionLog;
use App\Services\Security\SecurityDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SecurityApiController extends Controller
{
    /**
     * Outstanding intrusion count + latest event, polled by the topbar bell.
     *
     * The count is of events nobody has marked reviewed, so the badge stays up
     * until an administrator acts rather than clearing itself the moment the
     * Security Dashboard is opened. That is what makes it an alert: it reports
     * a backlog, not a page view.
     *
     * The JSON key is still `unseen` because public/js/app.js reads it; what it
     * counts is what changed.
     */
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

    public function stats(Request $request, SecurityDashboardService $security): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('security.dashboard'), 403);

        return response()->json([
            'intrusions_by_day' => $security->intrusionsByDay(),
        ]);
    }
}
