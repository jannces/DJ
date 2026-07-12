<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\EmployeeProfile;
use App\Models\LeaveRequest;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Global search across employees, leave requests and departments (permission-scoped). */
class SearchController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q'));
        $user = $request->user();
        $employees = collect();
        $requests = collect();
        $departments = collect();

        if (strlen($q) >= 2) {
            if ($user->hasPermission('employees.view')) {
                $employees = EmployeeProfile::with('department')
                    ->where(fn ($w) => $w->where('first_name', 'like', "%{$q}%")
                        ->orWhere('last_name', 'like', "%{$q}%")
                        ->orWhere('employee_no', 'like', "%{$q}%"))
                    ->limit(20)->get();
            }

            $requestQuery = LeaveRequest::with('user', 'leaveType')
                ->where('reference_no', 'like', "%{$q}%");
            if (! $user->hasPermission('leave.requests.view-all')) {
                $requestQuery->where('user_id', $user->id);
            }
            $requests = $requestQuery->limit(20)->get();

            if ($user->hasPermission('departments.manage')) {
                $departments = Department::where('name', 'like', "%{$q}%")
                    ->orWhere('code', 'like', "%{$q}%")->limit(20)->get();
            }
        }

        return view('search.index', compact('q', 'employees', 'requests', 'departments'));
    }
}
