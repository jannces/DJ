<?php

namespace App\Http\Controllers\Leave;

use App\Http\Controllers\Controller;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Leave\LeaveCreditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BalanceController extends Controller
{
    public function __construct(private readonly LeaveCreditService $credits)
    {
    }

    public function index(Request $request): View
    {
        $users = User::whereHas('employeeProfile')
            ->with(['employeeProfile.department', 'leaveBalances.leaveType'])
            ->when($request->string('q')->toString(), fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->orderBy('name')->paginate(15)->withQueryString();
        $types = LeaveType::where('deductible', true)->orWhereIn('code', ['VL', 'SL'])->get();

        return view('hr.balances', compact('users', 'types'));
    }

    public function adjust(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'leave_type_id' => ['required', 'exists:leave_types,id'],
            'days' => ['required', 'numeric'],
            'remarks' => ['required', 'string', 'max:255'],
        ]);

        $this->credits->adjust(
            $user,
            LeaveType::findOrFail($data['leave_type_id']),
            (float) $data['days'],
            $data['remarks'],
            $request->user(),
        );

        return back()->with('status', 'Balance adjusted.');
    }
}
