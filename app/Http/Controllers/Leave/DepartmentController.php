<?php

namespace App\Http\Controllers\Leave;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DepartmentController extends Controller
{
    public function index(): View
    {
        $departments = Department::withCount('employees')->with('head')->orderBy('name')->paginate(15);
        $heads = User::whereHas('roles', fn ($q) => $q->where('slug', 'department-head'))->get();

        return view('hr.departments', compact('departments', 'heads'));
    }

    public function create(): View
    {
        return redirect()->route('departments.index');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'code' => ['required', 'alpha_num', 'max:20', 'unique:departments,code'],
            'head_user_id' => ['nullable', 'exists:users,id'],
        ]);
        Department::create($data);

        return back()->with('status', 'Department created.');
    }

    public function edit(Department $department): View
    {
        return view('hr.departments', [
            'departments' => Department::withCount('employees')->with('head')->orderBy('name')->paginate(15),
            'heads' => User::whereHas('roles', fn ($q) => $q->where('slug', 'department-head'))->get(),
            'editing' => $department,
        ]);
    }

    public function update(Request $request, Department $department): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'code' => ['required', 'alpha_num', 'max:20', 'unique:departments,code,'.$department->id],
            'head_user_id' => ['nullable', 'exists:users,id'],
        ]);
        $department->update($data);

        return redirect()->route('departments.index')->with('status', 'Department updated.');
    }

    public function destroy(Department $department): RedirectResponse
    {
        $department->delete();

        return back()->with('status', 'Department archived.');
    }
}
