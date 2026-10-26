<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Support\AuditNarrator;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * A person's own audit trail.
 *
 * Separate from Admin\AuditLogController, which serves the whole log behind
 * `audit.view`. This one is scoped to the signed-in user and available to
 * every role the system audits -- employee, HR officer, department head and
 * Mayor.
 *
 * The scope comes from the session and nowhere else. There is no id in the
 * route, none in the query string and none in a hidden field, because a page
 * that accepts one is a page that can be asked for somebody else's trail by
 * editing the address bar. The only thing a request can influence here is
 * which of the holder's OWN rows are shown.
 */
class MyAuditController extends Controller
{
    public function index(Request $request): View
    {
        $userId = $request->user()->id;

        $logs = AuditLog::query()
            ->where('user_id', $userId)
            ->when($request->string('action')->toString(), fn ($q, $a) => $q->where('action', $a))
            // Cursor rather than offset, for the same reason as the admin
            // list: entries arrive at the top continuously, and with OFFSET
            // every arrival pushes page 2 down over rows already read.
            ->latest()->orderByDesc('id')
            ->cursorPaginate(config('lists.per_page'))->withQueryString();

        // The filter lists only actions THIS person has performed. Building it
        // from the whole table would leak the shape of everybody else's
        // activity through a dropdown -- a small leak, and an unnecessary one.
        $actions = AuditLog::query()
            ->where('user_id', $userId)
            ->distinct()->orderBy('action')->pluck('action')
            ->mapWithKeys(fn (string $a) => [$a => AuditNarrator::action($a)])
            ->sort();

        return view('audit.mine', compact('logs', 'actions'));
    }
}
