<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $logs = AuditLog::with('user')
            ->when($request->string('action')->toString(), fn ($q, $a) => $q->where('action', $a))
            ->when($request->string('q')->toString(), fn ($q, $u) => $q->whereHas('user', fn ($w) => $w->where('name', 'like', "%{$u}%")))
            ->latest()->paginate(config('lists.per_page'))->withQueryString();

        // Actions come from a fixed vocabulary the system writes itself, so
        // the filter is a list of what is actually in the log rather than a
        // box you had to guess the wording of.
        $actions = AuditLog::distinct()->orderBy('action')->pluck('action', 'action');

        return view('admin.audit.index', compact('logs', 'actions'));
    }
}
