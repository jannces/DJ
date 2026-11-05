<?php

namespace App\Http\Controllers\Leave;

use App\Http\Controllers\Controller;
use App\Models\Holiday;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HolidayController extends Controller
{
    public function index(): View
    {
        $holidays = Holiday::orderBy('date')->paginate(config('lists.per_page'));

        return view('hr.holidays', compact('holidays'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'name' => ['required', 'string', 'max:150'],
            'scope' => ['required', 'in:national,local'],
        ]);

        // The column holds a timestamp at midnight, so a bare 'Y-m-d' never
        // matches an existing row and the insert trips the unique index. Look
        // the day up the way it is stored, and re-saving a date replaces it —
        // which is what the form promises, there being no edit route.
        Holiday::updateOrCreate(
            ['date' => Carbon::parse($data['date'])->startOfDay()],
            ['name' => $data['name'], 'scope' => $data['scope']],
        );

        return back()->with('status', 'Holiday saved.');
    }

    public function destroy(Holiday $holiday): RedirectResponse
    {
        $holiday->delete();

        return back()->with('status', 'Holiday removed.');
    }
}
