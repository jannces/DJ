{{--
    Approval authority — PRESENTATION ONLY.

    Everything shown here is read back from the existing workflow: the Approval
    rows created for the request, the request's current step, and the existing
    permission that the workflow itself requires for that step
    (ApprovalWorkflowService::permissionForStep). This partial never decides who
    may approve; it reports what the system has already decided.

    Usage: @include('partials.approval-authority', ['request' => $leaveRequest])
--}}
@php
    $workflow = app(\App\Services\Leave\ApprovalWorkflowService::class);
    $chain = $request->approvals->sortBy('step_no');
    $current = $chain->firstWhere('step_no', $request->current_step);
    $requiredPermission = $current ? $workflow->permissionForStep($current->role_slug) : null;
    $viewerMayAct = $current
        && $current->action === 'pending'
        && ! $request->isFinal()
        && $requiredPermission
        && auth()->user()?->hasPermission($requiredPermission);
    $roleLabels = [
        'department_head' => 'Department Head',
        'hr' => 'HR Officer',
        'mayor' => 'Municipal Mayor',
    ];
    $actionLabels = [
        'approved' => ['Approved', 'status-ok', 'bi-check-circle'],
        'certified' => ['Certified', 'status-ok', 'bi-patch-check'],
        'rejected' => ['Disapproved', 'status-bad', 'bi-x-circle'],
        'returned' => ['Returned', 'status-wait', 'bi-arrow-counterclockwise'],
        'pending' => ['Awaiting action', 'status-idle', 'bi-hourglass'],
    ];
@endphp

<div class="authority">
    @foreach ($chain as $step)
        @php
            [$label, $tone, $icon] = $actionLabels[$step->action] ?? $actionLabels['pending'];
            $isCurrent = $current && $step->step_no === $current->step_no && $step->action === 'pending';
        @endphp
        <div class="auth-row {{ $isCurrent ? 'is-current' : '' }}">
            <span class="auth-role">
                <span class="step-no">{{ $step->step_no + 1 }}</span>
                <span>
                    {{ $roleLabels[$step->role_slug] ?? ucwords(str_replace('_', ' ', $step->role_slug)) }}
                    @if ($isCurrent)<span class="cell-meta d-block">Current approver</span>@endif
                </span>
            </span>
            <span class="status {{ $tone }}"><i class="bi {{ $icon }}" aria-hidden="true"></i>{{ $label }}</span>
        </div>
    @endforeach
</div>

@if ($viewerMayAct)
    <div class="authority-note can-act mt-3">
        <i class="bi bi-unlock" aria-hidden="true"></i>
        <span>
            <span class="title">This request is at your step</span>
            The workflow has this application waiting on
            {{ $roleLabels[$current->role_slug] ?? $current->role_slug }}, and your account holds that authority.
        </span>
    </div>
@elseif ($current && $current->action === 'pending' && ! $request->isFinal())
    <div class="authority-note restricted mt-3">
        <i class="bi bi-lock" aria-hidden="true"></i>
        <span>
            <span class="title">Action restricted</span>
            This application is awaiting {{ $roleLabels[$current->role_slug] ?? $current->role_slug }}.
            You cannot act on it at this step.
        </span>
    </div>
@else
    <div class="authority-note restricted mt-3">
        <i class="bi bi-flag" aria-hidden="true"></i>
        <span>
            <span class="title">Workflow closed</span>
            This application has reached a final state; no further approval action is available.
        </span>
    </div>
@endif
