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
            ->when($request->string('action')->toString(), fn ($q, $a) => $q->where('action', 'like', "%{$a}%"))
            ->when($request->string('user')->toString(), fn ($q, $u) => $q->whereHas('user', fn ($w) => $w->where('name', 'like', "%{$u}%")))
            ->latest()->paginate(30)->withQueryString();

        return view('admin.audit.index', compact('logs'));
    }
}
