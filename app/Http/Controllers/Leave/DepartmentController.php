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
        return view('hr.departments', $this->listing());
    }

    /**
     * One query shape, so index, create and edit cannot show different lists.
     *
     * @return array{departments:mixed, heads:mixed}
     */
    private function listing(): array
    {
        return [
            'departments' => Department::withCount('employees')->with('head')->orderBy('name')->paginate(config('lists.per_page')),
            'heads' => User::whereHas('roles', fn ($q) => $q->where('slug', 'department-head'))->get(),
        ];
    }

    /**
     * The list, with the New panel open.
     *
     * A real URL behind the button, so the page works with the script and
     * without it — see PositionController::create().
     */
    public function create(): View
    {
        return view('hr.departments', $this->listing() + ['opening' => true]);
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
        return view('hr.departments', $this->listing() + ['editing' => $department]);
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
