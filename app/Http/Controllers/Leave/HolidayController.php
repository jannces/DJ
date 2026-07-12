<?php

namespace App\Http\Controllers\Leave;

use App\Http\Controllers\Controller;
use App\Models\Holiday;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HolidayController extends Controller
{
    public function index(): View
    {
        $holidays = Holiday::orderBy('date')->paginate(20);

        return view('hr.holidays', compact('holidays'));
    }

    public function store(Request $request): RedirectResponse
    {
        Holiday::updateOrCreate(
            ['date' => $request->validate(['date' => ['required', 'date']])['date']],
            $request->validate([
                'name' => ['required', 'string', 'max:150'],
                'scope' => ['required', 'in:national,local'],
            ]),
        );

        return back()->with('status', 'Holiday saved.');
    }

    public function destroy(Holiday $holiday): RedirectResponse
    {
        $holiday->delete();

        return back()->with('status', 'Holiday removed.');
    }
}
