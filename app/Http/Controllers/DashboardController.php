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

        return view('dashboard.index', $data);
    }
}
