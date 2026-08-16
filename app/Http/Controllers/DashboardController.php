<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard)
    {
    }

    /** Routes each role to the dashboard it is permitted to see. */
    public function index(Request $request): View
    {
        $user = $request->user();
        $data = $this->dashboard->forUser($user);

        // HR staff are employees too: Overview is their personal leave, while
        // the organisation-wide picture lives at HR Management → Dashboard.
        if ($user->hasPermission('leave.certify.hr')) {
            return view('dashboard.overview', $data + $this->dashboard->personalOverview($user));
        }

        return view('dashboard.index', $data);
    }

    /** Organisation-wide HR context (same data, presented separately). */
    public function hr(Request $request): View
    {
        return view('dashboard.hr', $this->dashboard->hrOverview() + [
            'role' => $this->dashboard->primaryRole($request->user()),
        ]);
    }
}
