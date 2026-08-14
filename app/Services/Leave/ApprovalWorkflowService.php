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
 * Single-step leave approval.
 *
 *     Employee → Pending → ANY ONE of Mayor / Vice Mayor / HR → Approved|Rejected
 *
 * There is no Department Head step and no sequential chain: whichever authorized
 * officer acts first decides the application, and the decision is final. The step
 * is stored as the role-neutral slug "authorized" and gated by a single
 * permission, so adding another approving role is an RBAC grant rather than a
 * code change.
 *
 * Every action is recorded as an immutable Approval row carrying the approver,
 * the timestamp and a signature snapshot, which is what the employee-facing
 * approval timeline reads.
 */
class ApprovalWorkflowService
{
    /** The only step in the workflow. */
    public const STEP = 'authorized';

    /** Permission that authorizes deciding an application. */
    public const STEP_PERMISSION = 'leave.approve.final';

    public function __construct(
        private readonly LeaveCreditService $credits,
        private readonly AuditLogger $audit,
    ) {
    }

    /** Create the single pending decision and put the request in the queue. */
    public function initialize(LeaveRequest $request, LeaveType $type): void
    {
        Approval::create([
            'leave_request_id' => $request->id,
            'step_no' => 0,
            'role_slug' => self::STEP,
            'action' => Approval::ACTION_PENDING,
        ]);

        $request->update([
            'current_step' => 0,
            'status' => LeaveRequest::STATUS_PENDING,
        ]);

        $request->user->notify(new LeaveStatusNotification($request, 'submitted'));
    }

    public function permissionForStep(string $roleSlug): ?string
    {
        return self::STEP_PERMISSION;
    }

    public function currentApproval(LeaveRequest $request): ?Approval
    {
        return $request->approvals()->where('step_no', $request->current_step)->first();
    }

    /** Any of Mayor, Vice Mayor or HR may decide — whoever holds the permission. */
    public function canDecide(User $user): bool
    {
        return $user->hasPermission(self::STEP_PERMISSION);
    }

    /**
     * Record a decision. The first authorized officer to act settles the
     * application; later attempts are refused so two approvers cannot disagree.
     *
     * @param  string  $action  approved|rejected|returned
     * @param  array   $extra   comments, days_with_pay/without_pay, certified_balances, signature
     */
    public function act(LeaveRequest $request, User $actor, string $action, array $extra = []): LeaveRequest
    {
        // Already approved, rejected or cancelled? Nothing may change it.
        if ($request->isFinal()) {
            throw ValidationException::withMessages([
                'status' => 'This application has already been decided and can no longer be changed.',
            ]);
        }

        $approval = $this->currentApproval($request);
        if (! $approval || $approval->action !== Approval::ACTION_PENDING) {
            throw ValidationException::withMessages(['status' => 'There is no pending decision to act on.']);
        }

        if (! $this->canDecide($actor)) {
            throw ValidationException::withMessages(['status' => 'You are not authorized to decide leave applications.']);
        }

        // An employee may never decide their own application, whatever else they hold.
        if ($request->user_id === $actor->id) {
            throw ValidationException::withMessages(['status' => 'You cannot decide your own leave application.']);
        }

        return DB::transaction(function () use ($request, $actor, $action, $extra, $approval) {
            // Re-read under the transaction so two officers acting at the same
            // moment cannot both pass the pending check above.
            $locked = Approval::whereKey($approval->id)->lockForUpdate()->first();
            if (! $locked || $locked->action !== Approval::ACTION_PENDING) {
                throw ValidationException::withMessages([
                    'status' => 'Another authorized officer has just decided this application.',
                ]);
            }

            $locked->update([
                'approver_id' => $actor->id,
                'action' => $this->normalizeAction($action),
                'comments' => $extra['comments'] ?? null,
                'days_with_pay' => $extra['days_with_pay'] ?? null,
                'days_without_pay' => $extra['days_without_pay'] ?? null,
                'certified_balances' => $extra['certified_balances'] ?? null,
                'signature' => $extra['signature'] ?? $actor->name,
                'acted_at' => now(),
            ]);

            if ($action === 'returned') {
                // Sent back to the employee for revision; the step reopens.
                $locked->update(['action' => Approval::ACTION_PENDING, 'acted_at' => null, 'approver_id' => null]);
                $request->update(['status' => LeaveRequest::STATUS_RETURNED]);
                $this->finish($request, $actor, 'returned');

                return $request;
            }

            if ($action === 'rejected') {
                $request->update([
                    'status' => LeaveRequest::STATUS_REJECTED,
                    'disapproval_reason' => $extra['comments'] ?? null,
                    'decided_at' => now(),
                ]);
                $this->finish($request, $actor, 'rejected');

                return $request;
            }

            // Approved — final, with the pay split and the automatic deduction.
            $request->update([
                'status' => LeaveRequest::STATUS_APPROVED,
                'days_with_pay' => $extra['days_with_pay'] ?? $request->working_days,
                'days_without_pay' => $extra['days_without_pay'] ?? 0,
                'decided_at' => now(),
            ]);
            $this->credits->deductForApproval($request, $actor);
            $this->finish($request, $actor, 'approved');

            return $request;
        });
    }

    /** Re-submit a returned request. */
    public function resubmit(LeaveRequest $request, User $actor): void
    {
        if ($request->status !== LeaveRequest::STATUS_RETURNED) {
            throw ValidationException::withMessages(['status' => 'Only returned requests can be resubmitted.']);
        }
        $request->update(['status' => LeaveRequest::STATUS_PENDING]);
        $this->audit->log('leave_resubmitted', $request, [], [], $actor);
    }

    private function normalizeAction(string $action): string
    {
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
