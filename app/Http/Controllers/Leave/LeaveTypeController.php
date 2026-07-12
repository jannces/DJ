<?php

namespace App\Http\Controllers\Leave;

use App\Http\Controllers\Controller;
use App\Models\LeaveType;
use App\Services\Security\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LeaveTypeController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function index(): View
    {
        $types = LeaveType::orderBy('name')->paginate(20);

        return view('hr.leave-types.index', compact('types'));
    }

    public function create(): View
    {
        return view('hr.leave-types.form', ['type' => new LeaveType]);
    }

    public function store(Request $request): RedirectResponse
    {
        $type = LeaveType::create($this->validated($request));
        $this->audit->log('leave_type_created', $type, [], $type->getAttributes());

        return redirect()->route('leave-types.index')->with('status', 'Leave type created.');
    }

    public function edit(LeaveType $leaveType): View
    {
        return view('hr.leave-types.form', ['type' => $leaveType]);
    }

    public function update(Request $request, LeaveType $leaveType): RedirectResponse
    {
        $old = $leaveType->getAttributes();
        $leaveType->update($this->validated($request, $leaveType));
        $this->audit->log('leave_type_updated', $leaveType, $old, $leaveType->getChanges());

        return redirect()->route('leave-types.index')->with('status', 'Leave type updated.');
    }

    public function destroy(LeaveType $leaveType): RedirectResponse
    {
        if (! $leaveType->is_custom) {
            return back()->with('error', 'Standard CSC leave types cannot be deleted; deactivate them instead.');
        }
        $leaveType->update(['active' => false]);
        $this->audit->log('leave_type_deactivated', $leaveType);

        return back()->with('status', 'Custom leave type deactivated.');
    }

    private function validated(Request $request, ?LeaveType $type = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'alpha_dash', 'max:20', 'unique:leave_types,code'.($type ? ','.$type->id : '')],
            'name' => ['required', 'string', 'max:150'],
            'category' => ['required', 'in:regular,special,monetization,terminal'],
            'max_days' => ['nullable', 'numeric', 'min:0'],
            'deductible' => ['nullable', 'boolean'],
            'credit_source' => ['nullable', 'in:vacation,sick'],
            'requires_medical_after_days' => ['nullable', 'integer', 'min:0'],
            'filing_deadline_days' => ['nullable', 'integer', 'min:0'],
            'deadline_is_hard' => ['nullable', 'boolean'],
            'annual_reset' => ['nullable', 'boolean'],
            'active' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $data['deductible'] = $request->boolean('deductible');
        $data['deadline_is_hard'] = $request->boolean('deadline_is_hard');
        $data['annual_reset'] = $request->boolean('annual_reset');
        $data['active'] = $request->boolean('active', true);
        $data['is_custom'] = $type?->is_custom ?? true;
        $data['approval_flow'] = $type?->approval_flow ?? ['department_head', 'hr', 'mayor'];

        return $data;
    }
}
