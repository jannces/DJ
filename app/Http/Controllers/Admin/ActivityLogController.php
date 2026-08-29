<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ActivityLogController extends Controller
{
    public function index(Request $request): View
    {
        $logs = ActivityLog::with('user')
            ->when($request->string('q')->toString(), fn ($q, $u) => $q->whereHas('user', fn ($w) => $w->where('name', 'like', "%{$u}%")))
            ->when($request->string('method')->toString(), fn ($q, $m) => $q->where('method', $m))
            // Cursor, not offset: every request written while somebody is
            // reading shifts an offset page. See AuditLogController.
            ->latest()->orderByDesc('id')
            ->cursorPaginate(config('lists.per_page'))->withQueryString();

        // The method column was shown but could not be asked about, and it is
        // the difference between someone reading and someone changing things.
        $methods = ['GET' => 'GET', 'POST' => 'POST', 'PUT' => 'PUT', 'PATCH' => 'PATCH', 'DELETE' => 'DELETE'];

        return view('admin.activity.index', compact('logs', 'methods'));
    }
}
