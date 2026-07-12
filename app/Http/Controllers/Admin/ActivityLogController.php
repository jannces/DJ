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
            ->when($request->string('user')->toString(), fn ($q, $u) => $q->whereHas('user', fn ($w) => $w->where('name', 'like', "%{$u}%")))
            ->latest()->paginate(30)->withQueryString();

        return view('admin.activity.index', compact('logs'));
    }
}
