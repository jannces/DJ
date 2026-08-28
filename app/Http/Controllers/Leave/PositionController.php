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
        return view('hr.positions', ['positions' => $this->listing()]);
    }

    /** One query shape, so index, create and edit cannot show different lists. */
    private function listing()
    {
        return Position::withCount('employees')->orderBy('title')->paginate(15);
    }

    /**
     * The list, with the New panel open.
     *
     * The "New position" button is a real link here rather than a bare button,
     * so the page works with the script and without it: Bootstrap opens the
     * panel in place and cancels the navigation, and if the script never runs
     * the link loads this, which renders the panel already open.
     */
    public function create(): View
    {
        return view('hr.positions', [
            'positions' => $this->listing(),
            'opening' => true,
        ]);
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
            'positions' => $this->listing(),
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
