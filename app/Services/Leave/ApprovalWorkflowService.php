<?php

namespace App\Services\Leave;

use App\Models\Approval;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Notifications\LeaveStatusNotification;
use App\Services\Security\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Drives the CSC Form 6 approval chain:
 * Department Head → HR (certify) → Mayor (final) → auto balance deduction.
 * Each step maps to a role slug and a permission; actions are recorded as
 * immutable Approval rows with a digital signature snapshot.
 */
class ApprovalWorkflowService
{
    /** Role slug ⇒ permission that authorizes acting on that step. */
    private const STEP_PERMISSION = [
        'department_head' => 'leave.review.department',
        'hr' => 'leave.certify.hr',
        'mayor' => 'leave.approve.final',
    ];

    private const STEP_STATUS = [
        'department_head' => LeaveRequest::STATUS_DEPT_REVIEW,
        'hr' => LeaveRequest::STATUS_HR_REVIEW,
        'mayor' => LeaveRequest::STATUS_FINAL_REVIEW,
    ];

    public function __construct(
        private readonly LeaveCreditService $credits,
        private readonly AuditLogger $audit,
    ) {
    }

    /** Create the pending approval rows and move the request to the first step. */
    public function initialize(LeaveRequest $request, LeaveType $type): void
    {
        $steps = $type->workflowSteps();
        foreach ($steps as $index => $roleSlug) {
            Approval::create([
                'leave_request_id' => $request->id,
                'step_no' => $index,
                'role_slug' => $roleSlug,
                'action' => Approval::ACTION_PENDING,
            ]);
        }

        $first = $steps[0] ?? 'hr';
        $request->update([
            'current_step' => 0,
            'status' => self::STEP_STATUS[$first] ?? LeaveRequest::STATUS_PENDING,
        ]);

        $request->user->notify(new LeaveStatusNotification($request, 'submitted'));
    }

    public function permissionForStep(string $roleSlug): ?string
    {
        return self::STEP_PERMISSION[$roleSlug] ?? null;
    }

    public function currentApproval(LeaveRequest $request): ?Approval
    {
        return $request->approvals()->where('step_no', $request->current_step)->first();
    }

    /**
     * Apply a decision at the current step.
     *
     * @param  string  $action  approved|rejected|returned
     * @param  array   $extra   comments, days_with_pay/without_pay, certified_balances, signature
     */
    public function act(LeaveRequest $request, User $actor, string $action, array $extra = []): LeaveRequest
    {
        $approval = $this->currentApproval($request);
        if (! $approval || $approval->action !== Approval::ACTION_PENDING) {
            throw ValidationException::withMessages(['status' => 'There is no pending step to act on.']);
        }

        $permission = $this->permissionForStep($approval->role_slug);
        if ($permission && ! $actor->hasPermission($permission)) {
            throw ValidationException::withMessages(['status' => 'You are not authorized to act on this step.']);
        }

        // Department Heads may only act on their own department's requests.
        if ($approval->role_slug === 'department_head'
            && ! $actor->hasPermission('leave.requests.view-all')
            && $request->user->employeeProfile?->department_id !== $actor->employeeProfile?->department_id) {
            throw ValidationException::withMessages(['status' => 'This request is outside your department.']);
        }

        return DB::transaction(function () use ($request, $actor, $action, $extra, $approval) {
            $isFinalStep = $approval->step_no === $request->approvals()->max('step_no');

            $approval->update([
                'approver_id' => $actor->id,
                'action' => $this->normalizeAction($action, $approval->role_slug),
                'comments' => $extra['comments'] ?? null,
                'days_with_pay' => $extra['days_with_pay'] ?? null,
                'days_without_pay' => $extra['days_without_pay'] ?? null,
                'certified_balances' => $extra['certified_balances'] ?? null,
                'signature' => $extra['signature'] ?? $actor->name,
                'acted_at' => now(),
            ]);

            if ($action === 'rejected') {
                $request->update([
                    'status' => LeaveRequest::STATUS_REJECTED,
                    'disapproval_reason' => $extra['comments'] ?? null,
                    'decided_at' => now(),
                ]);
                $this->finish($request, $actor, 'rejected');

                return $request;
            }

            if ($action === 'returned') {
                $request->update(['status' => LeaveRequest::STATUS_RETURNED]);
                // Reopen this step so the employee can revise and it re-enters here.
                $approval->update(['action' => Approval::ACTION_PENDING, 'acted_at' => null, 'approver_id' => null]);
                $request->update(['status' => LeaveRequest::STATUS_RETURNED]);
                $this->finish($request, $actor, 'returned');

                return $request;
            }

            // Approved / certified: advance to next step or finalize.
            if ($isFinalStep) {
                // Final approver sets pay split and grants approval.
                $request->update([
                    'status' => LeaveRequest::STATUS_APPROVED,
                    'days_with_pay' => $extra['days_with_pay'] ?? $request->working_days,
                    'days_without_pay' => $extra['days_without_pay'] ?? 0,
                    'decided_at' => now(),
                ]);
                // Automatic balance deduction on final approval.
                $this->credits->deductForApproval($request, $actor);
                $this->finish($request, $actor, 'approved');
            } else {
                $nextStep = $request->current_step + 1;
                $nextRole = $request->approvals()->where('step_no', $nextStep)->value('role_slug');
                $request->update([
                    'current_step' => $nextStep,
                    'status' => self::STEP_STATUS[$nextRole] ?? LeaveRequest::STATUS_HR_REVIEW,
                ]);
                $this->audit->log('leave_step_'.$action, $request, [], ['step' => $approval->role_slug], $actor);
                $request->user->notify(new LeaveStatusNotification($request, $action, $approval->role_slug));
            }

            return $request;
        });
    }

    /** Re-submit a returned request back into the workflow at its current step. */
    public function resubmit(LeaveRequest $request, User $actor): void
    {
        if ($request->status !== LeaveRequest::STATUS_RETURNED) {
            throw ValidationException::withMessages(['status' => 'Only returned requests can be resubmitted.']);
        }
        $role = $request->approvals()->where('step_no', $request->current_step)->value('role_slug');
        $request->update(['status' => self::STEP_STATUS[$role] ?? LeaveRequest::STATUS_PENDING]);
        $this->audit->log('leave_resubmitted', $request, [], [], $actor);
    }

    private function normalizeAction(string $action, string $roleSlug): string
    {
        if ($action === 'approved' && $roleSlug === 'hr') {
            return Approval::ACTION_CERTIFIED;
        }

        return match ($action) {
            'approved' => Approval::ACTION_APPROVED,
            'rejected' => Approval::ACTION_REJECTED,
            'returned' => Approval::ACTION_RETURNED,
            default => $action,
        };
    }

    private function finish(LeaveRequest $request, User $actor, string $outcome): void
    {
        $this->audit->log('leave_'.$outcome, $request, [], ['by' => $actor->name], $actor);
        $request->user->notify(new LeaveStatusNotification($request, $outcome));
    }
}
