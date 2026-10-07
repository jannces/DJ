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
            ->when($request->string('position')->toString(), fn ($q, $p) => $q->whereHas('employeeProfile', fn ($w) => $w->where('position_id', $p)))
            ->orderBy('name')->paginate(config('lists.per_page'))->withQueryString();

        return view('hr.employees', [
            'employees' => $employees,
            'departments' => \App\Models\Department::orderBy('name')->pluck('name', 'id'),
            'positions' => \App\Models\Position::orderBy('title')->pluck('title', 'id'),
        ]);
    }

    public function show(Request $request, User $user): View
    {
        abort_unless($user->employeeProfile, 404);
        $user->load('employeeProfile.department', 'employeeProfile.position', 'roles', 'leaveBalances.leaveType');
        // Paged rather than capped: a long-serving employee's older requests
        // were simply unreachable past the thirtieth.
        $requests = $user->leaveRequests()->with('leaveType')->latest()
            ->paginate(config('lists.per_page'));

        return view('hr.employee-show', compact('user', 'requests'));
    }
}
