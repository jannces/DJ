<?php

namespace App\Services\Leave;

use App\Models\Approval;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Notifications\DepartmentLeaveFiledNotification;
use App\Notifications\LeaveStatusNotification;
use App\Services\Security\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Single-step leave approval, as the LGU runs it.
 *
 *     Employee files
 *        → the head of their office is NOTIFIED   (nothing to act on)
 *        → HR validates and decides
 *        → Approved | Disapproved
 *
 * THE DEPARTMENT HEAD IS TOLD, NOT ASKED. The head needs to know that one of
 * their people is going to be away — that is a staffing fact they have to plan
 * around — but the decision is HR's, and putting a head between the employee
 * and HR only bought a place for an application to sit while somebody was on
 * field work. So the head gets a notification and a read-only view of their
 * own office, and the application goes straight to the queue.
 *
 * The notification is also WRITTEN DOWN, as an Approval row carrying
 * ACTION_NOTIFIED. Two things need that record and neither is served by an
 * unread-count in a bell:
 *
 *   · CSC Form No. 6 box 7.B names the head of the applicant's office, and a
 *     form reprinted later must name who held that office on the day of
 *     filing. The row snapshots the name.
 *
 *   · The employee's own timeline can then state that the head was informed,
 *     with the timestamp, instead of asking them to take it on faith.
 *
 * WHICH HEAD. The one named on the applicant's office —
 * `departments.head_user_id`, maintained on the Departments page — not
 * "whoever holds the Department Head role and happens to work there". One
 * named person, and no ambiguity when an office has two.
 *
 * THE MAYOR NO LONGER DECIDES. Nothing here names a role: the decision is
 * gated on `leave.approve.final`, which now only HR holds. The Mayor keeps
 * sight of every application and the reports behind them, and signs the
 * printed form at its foot as head of agency — which is where a mayor's
 * signature belongs on CSC Form No. 6 anyway.
 *
 * Every decision is recorded as an immutable Approval row carrying the
 * approver, the timestamp and a signature snapshot, which is what the
 * employee-facing approval timeline reads.
 */
class ApprovalWorkflowService
{
    /** Step 0 — a record that the applicant's department head was informed. */
    public const STEP_DEPARTMENT = 'department';

    /** Step 1 — the decision. Kept as "authorized": any holder may act. */
    public const STEP = 'authorized';

    /** Permission that authorizes deciding an application. */
    public const STEP_PERMISSION = 'leave.approve.final';

    /**
     * Permission that lets a head see their own office's leave.
     *
     * The slug still says "review" because it is written into route guards,
     * menu entries and existing installations' permission tables; what it
     * grants is now visibility, not authority. Nothing in this service asks
     * for it — a head cannot act on an application at all.
     */
    public const DEPARTMENT_PERMISSION = 'leave.review.department';

    public function __construct(
        private readonly LeaveCreditService $credits,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Put the application in HR's queue and tell the applicant's head.
     *
     * One pending step, always — there is nothing between the employee and the
     * decision. The department row, when there is a head to record, is written
     * already closed as ACTION_NOTIFIED so that no query looking for open work
     * can ever find it.
     */
    public function initialize(LeaveRequest $request, LeaveType $type): void
    {
        $head = $this->departmentHeadFor($request);

        if ($head !== null) {
            Approval::create([
                'leave_request_id' => $request->id,
                'step_no' => 0,
                'role_slug' => self::STEP_DEPARTMENT,
                'approver_id' => $head->id,
                'action' => Approval::ACTION_NOTIFIED,
                // The name as it stood on the day of filing — box 7.B of the
                // printed form reads this, not today's head of the office.
                'signature' => $head->name,
                'acted_at' => now(),
            ]);
        }

        Approval::create([
            'leave_request_id' => $request->id,
            'step_no' => 1,
            'role_slug' => self::STEP,
            'action' => Approval::ACTION_PENDING,
        ]);

        $request->update([
            'current_step' => 1,
            'status' => LeaveRequest::STATUS_PENDING,
        ]);

        $request->user->notify(new LeaveStatusNotification($request, 'submitted'));

        // Sent after the request is in the queue, not before: a head told about
        // an application that then failed to save would be told about nothing.
        $head?->notify(new DepartmentLeaveFiledNotification($request));
    }

    /**
     * The head to inform about this application, or null if there is nobody.
     *
     * Null in three cases:
     *
     *   · the applicant has no department on record;
     *   · the office has no head assigned;
     *   · the applicant IS their office's head. Telling somebody what they
     *     have just done themselves is noise, and box 7.B would carry the
     *     applicant's own name twice.
     */
    public function departmentHeadFor(LeaveRequest $request): ?User
    {
        $department = $request->user?->employeeProfile?->department;

        if ($department?->head_user_id === null) {
            return null;
        }

        if ((int) $department->head_user_id === (int) $request->user_id) {
            return null;
        }

        return $department->head;
    }

    /**
     * The head recorded as notified when this application was filed.
     *
     * Reads the snapshot, so a printed form names who headed the office then.
     * Falls back to the live head only for applications filed before the
     * notification row existed.
     */
    public function notifiedHeadName(LeaveRequest $request): ?string
    {
        $row = $request->approvals
            ->firstWhere('role_slug', self::STEP_DEPARTMENT);

        return $row?->signature
            ?? $row?->approver?->name
            ?? $this->departmentHeadFor($request)?->name;
    }

    public function permissionForStep(string $roleSlug): ?string
    {
        return self::STEP_PERMISSION;
    }

    public function currentApproval(LeaveRequest $request): ?Approval
    {
        return $request->approvals()->where('step_no', $request->current_step)->first();
    }

    /**
     * Whoever holds the permission decides — which is HR, and only HR.
     *
     * Gated on the permission rather than on a role slug, so an administrator
     * who grants it to somebody else does not have to change any code, and so
     * withdrawing it from the Mayor took one seeder line rather than a rewrite.
     */
    public function canDecide(User $user): bool
    {
        return $user->hasPermission(self::STEP_PERMISSION);
    }

    /**
     * Record HR's decision.
     *
     * The first authorized officer to act settles the application; later
     * attempts are refused so two approvers cannot disagree.
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

        // An employee may never act on their own application, whatever else
        // they hold. Checked before anything else, because it is the one rule
        // that has no exception at either step.
        if ($request->user_id === $actor->id) {
            throw ValidationException::withMessages(['status' => 'You cannot decide your own leave application.']);
        }

        // There is one step and one authority. A department head reaching this
        // — from a stale queue page, or by posting the route directly — is
        // refused here rather than merely being absent from a list.
        if (! $this->canDecide($actor)) {
            throw ValidationException::withMessages(['status' => 'You are not authorized to decide leave applications.']);
        }

        $approval = $request->approvals()->where('step_no', 1)->first();
        if (! $approval || $approval->action !== Approval::ACTION_PENDING) {
            throw ValidationException::withMessages(['status' => 'There is no pending decision to act on.']);
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

    /**
     * Re-submit a returned request.
     *
     * There is one step to come back to, so it comes back to HR. The head is
     * not told again: they were told when it was first filed, and the fact
     * they need — that this person intends to be away — has not changed.
     */
    public function resubmit(LeaveRequest $request, User $actor): void
    {
        if ($request->status !== LeaveRequest::STATUS_RETURNED) {
            throw ValidationException::withMessages(['status' => 'Only returned requests can be resubmitted.']);
        }

        $request->update([
            'current_step' => 1,
            'status' => LeaveRequest::STATUS_PENDING,
        ]);
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
