<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\AuditNarrator;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $logs = AuditLog::with('user')
            ->when($request->string('action')->toString(), fn ($q, $a) => $q->where('action', $a))
            ->when($request->string('q')->toString(), fn ($q, $u) => $q->whereHas('user', fn ($w) => $w->where('name', 'like', "%{$u}%")))
            // Cursor, not offset. Entries arrive at the top of this list
            // continuously, and with OFFSET every arrival pushes the list down
            // -- page 2 then re-shows rows already read on page 1, or skips
            // past them. A cursor anchors to a row, so neither can happen.
            // id breaks ties: two entries can share a timestamp, and a cursor
            // needs a total order to anchor to.
            ->latest()->orderByDesc('id')
            ->cursorPaginate(config('lists.per_page'))->withQueryString();

        // Actions come from a fixed vocabulary the system writes itself, so
        // the filter is a list of what is actually in the log rather than a
        // box you had to guess the wording of. The slug stays the value --
        // that is what the query filters on -- and only the wording changes,
        // so the dropdown reads the same as the column beside it.
        $actions = AuditLog::distinct()->orderBy('action')->pluck('action')
            ->mapWithKeys(fn (string $a) => [$a => AuditNarrator::action($a)])
            ->sort();

        return view('admin.audit.index', compact('logs', 'actions'));
    }
}
