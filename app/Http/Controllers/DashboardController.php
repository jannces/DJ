<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard)
    {
    }

    /** Routes each role to the dashboard it is permitted to see. */
    public function index(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        // The System Administrator has one dashboard, and it is the security
        // one. They hold no leave permission, so this page would otherwise be
        // an empty frame — and everything they actually administer is already
        // on the other screen.
        //
        // The redirect lives here rather than in config/menu.php: the sidebar
        // is not touched, so both `Dashboard` and `Security Dashboard` stay
        // exactly where they are and both arrive at the same place.
        if (! $user->hasPermission('leave.view-own')
            && ! $user->hasPermission('leave.requests.view-all')
            && $user->hasPermission('security.dashboard')) {
            return redirect()->route('security.dashboard');
        }

        return view('dashboard.index', $this->dashboard->forUser($user));
    }
}
