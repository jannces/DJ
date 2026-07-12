<?php

namespace App\Http\Controllers\Leave;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EmployeeController extends Controller
{
    public function index(Request $request): View
    {
        $employees = User::whereHas('employeeProfile')
            ->with(['employeeProfile.department', 'employeeProfile.position', 'roles'])
            ->when($request->string('q')->toString(), function ($q, $s) {
                $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")
                    ->orWhereHas('employeeProfile', fn ($e) => $e->where('employee_no', 'like', "%{$s}%")));
            })
            ->when($request->string('department')->toString(), fn ($q, $d) => $q->whereHas('employeeProfile', fn ($w) => $w->where('department_id', $d)))
            ->orderBy('name')->paginate(15)->withQueryString();
        $departments = \App\Models\Department::orderBy('name')->get();

        return view('hr.employees', compact('employees', 'departments'));
    }

    public function show(Request $request, User $user): View
    {
        abort_unless($user->employeeProfile, 404);
        $user->load('employeeProfile.department', 'employeeProfile.position', 'roles', 'leaveBalances.leaveType');
        $requests = $user->leaveRequests()->with('leaveType')->latest()->limit(30)->get();
        $history = $user->leaveHistory()->with('leaveType')->latest()->limit(50)->get();

        return view('hr.employee-show', compact('user', 'requests', 'history'));
    }
}
