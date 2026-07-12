<?php

namespace App\Http\Controllers\Leave;

use App\Http\Controllers\Controller;
use App\Models\Position;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PositionController extends Controller
{
    public function index(): View
    {
        $positions = Position::withCount('employees')->orderBy('title')->paginate(15);

        return view('hr.positions', compact('positions'));
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('positions.index');
    }

    public function store(Request $request): RedirectResponse
    {
        Position::create($request->validate([
            'title' => ['required', 'string', 'max:150'],
            'salary_grade' => ['nullable', 'string', 'max:10'],
        ]));

        return back()->with('status', 'Position created.');
    }

    public function edit(Position $position): View
    {
        return view('hr.positions', [
            'positions' => Position::withCount('employees')->orderBy('title')->paginate(15),
            'editing' => $position,
        ]);
    }

    public function update(Request $request, Position $position): RedirectResponse
    {
        $position->update($request->validate([
            'title' => ['required', 'string', 'max:150'],
            'salary_grade' => ['nullable', 'string', 'max:10'],
        ]));

        return redirect()->route('positions.index')->with('status', 'Position updated.');
    }

    public function destroy(Position $position): RedirectResponse
    {
        $position->delete();

        return back()->with('status', 'Position archived.');
    }
}
