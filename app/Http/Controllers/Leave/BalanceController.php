<?php

namespace App\Http\Controllers\Leave;

use App\Http\Controllers\Controller;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Leave\LeaveCreditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;

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
            // Balances are reviewed office by office, so the office is the
            // filter HR actually reaches for.
            ->when($request->string('department')->toString(), fn ($q, $d) => $q->whereHas('employeeProfile', fn ($w) => $w->where('department_id', $d)))
            ->orderBy('name')->paginate(config('lists.per_page'))->withQueryString();
        $types = $this->adjustableTypes();

        return view('hr.balances', [
            'users' => $users,
            'types' => $types,
            'departments' => \App\Models\Department::orderBy('name')->pluck('name', 'id'),
        ]);
    }

    /**
     * Adjust any number of an employee's balances in one go.
     *
     * It used to take one leave type per submit, so correcting both Vacation
     * and Sick meant opening the dialog, applying, waiting for the page,
     * opening it again and applying again -- and writing the reason out twice.
     * The form now carries a field per type; the ones left blank are left
     * alone.
     *
     * All of them commit together. Adjusting two types in two transactions
     * means a failure on the second leaves the first applied, and the officer
     * is looking at a page that says one thing while the ledger says another.
     */
    public function adjust(Request $request, User $user): RedirectResponse
    {
        $types = $this->adjustableTypes()->keyBy('id');

        $data = $request->validate([
            // Keyed by leave type id. Only the ids this page offers are
            // accepted -- the key comes from the form, so it is checked
            // against the allowed set rather than trusted.
            'days' => ['required', 'array'],
            'days.*' => ['nullable', 'numeric', 'between:-3650,3650'],
            'remarks' => ['required', 'string', 'max:255'],
        ], [], ['days.*' => 'number of days']);

        $changes = [];
        foreach ($data['days'] as $typeId => $days) {
            if ($days === null || $days === '' || (float) $days === 0.0) {
                continue;
            }

            $type = $types->get((int) $typeId);
            abort_if($type === null, 422, 'That leave type cannot be adjusted here.');

            $changes[] = [$type, (float) $days];
        }

        if (! $changes) {
            return back()
                ->withErrors(['days' => 'Enter a number of days for at least one leave type.'])
                ->withInput()
                ->with('adjusting', $user->id);
        }

        /**
         * Every refusal, not just the first.
         *
         * The service refuses an adjustment that would take a balance below
         * zero. Uncaught that was a 500 page: no message, and everything the
         * officer had typed gone. Caught one at a time it would still be a
         * round trip per bad row, which is the complaint this whole change is
         * answering -- so the loop collects them all and reports them together.
         *
         * The service stays the authority on what is allowed; this only asks
         * it about each row before giving up. Rolling back by throwing keeps
         * the all-or-nothing guarantee: a set with one bad row applies none of
         * it, so the page and the ledger cannot disagree.
         */
        $refused = [];

        try {
            DB::transaction(function () use ($changes, $user, $data, $request, &$refused) {
                foreach ($changes as [$type, $days]) {
                    try {
                        $this->credits->adjust($user, $type, $days, $data['remarks'], $request->user());
                    } catch (RuntimeException $e) {
                        // Named, because five rows are on screen and "the
                        // balance" does not say which one is the problem.
                        $refused[] = $type->name.': '.$e->getMessage();
                    }
                }

                if ($refused) {
                    throw new RuntimeException('rolled back');
                }
            });
        } catch (RuntimeException $e) {
            return back()
                ->withErrors(['days' => $refused ? implode(' ', $refused) : $e->getMessage()])
                ->withInput()
                ->with('adjusting', $user->id);
        }

        return back()->with('status', count($changes) === 1
            ? 'Balance adjusted.'
            : count($changes).' balances adjusted.');
    }

    /**
     * The leave types this page can adjust.
     *
     * Shared by the form and the handler so the two cannot disagree about
     * what is allowed -- a type the form does not offer is a type the handler
     * refuses.
     */
    private function adjustableTypes()
    {
        return LeaveType::where('deductible', true)
            ->orWhereIn('code', ['VL', 'SL'])
            ->orderByRaw("CASE code WHEN 'VL' THEN 0 WHEN 'SL' THEN 1 ELSE 2 END")
            ->orderBy('name')
            ->get();
    }
}
