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
 * Two-step leave approval, as the LGU runs it.
 *
 *     Employee files
 *        → DEPARTMENT REVIEW   the head of the applicant's own office
 *        → DECISION            ANY ONE of Mayor / HR
 *        → Approved | Rejected
 *
 * THE DEPARTMENT STEP IS FOR AWARENESS, NOT CONTROL. Its purpose is that the
 * head knows one of their people is going to be away and puts that on the
 * record. Three things follow from that, and they are the whole design:
 *
 *   · A head who does not endorse does not kill the application. The
 *     recommendation — either way — travels on to the Mayor or HR with its
 *     comment attached, and they decide. The role says "reviews and
 *     recommends", and CSC Form No. 6 keeps the recommendation and the
 *     approval as two separate signature blocks for exactly this reason.
 *
 *   · The step can never strand a request. An authorized officer may decide an
 *     application still sitting at department review; the department row is
 *     then closed as `skipped` so the timeline says plainly that no
 *     recommendation was recorded, rather than leaving a silent gap.
 *
 *   · A step with nobody to act it does not exist. See needsDepartmentReview().
 *
 * WHO REVIEWS. The head named on the applicant's office —
 * `departments.head_user_id`, maintained on the Departments page — not
 * "whoever holds the Department Head role and happens to be in that office".
 * One named person, and no ambiguity when an office has two.
 *
 * Every action is recorded as an immutable Approval row carrying the approver,
 * the timestamp and a signature snapshot, which is what the employee-facing
 * approval timeline reads.
 */
class ApprovalWorkflowService
{
    /** Step 0 — the applicant's own department head recommends. */
    public const STEP_DEPARTMENT = 'department';

    /** Step 1 — the decision. Kept as "authorized": any holder may act. */
    public const STEP = 'authorized';

    /** Permission that authorizes deciding an application. */
    public const STEP_PERMISSION = 'leave.approve.final';

    /** Permission that authorizes recommending at department level. */
    public const DEPARTMENT_PERMISSION = 'leave.review.department';

    public function __construct(
        private readonly LeaveCreditService $credits,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Open the steps this application actually has and put it in the queue.
     *
     * The department row is only created when there is somebody to act it, so
     * "is there a department step" is a question about the rows rather than a
     * flag that can drift out of step with them.
     */
    public function initialize(LeaveRequest $request, LeaveType $type): void
    {
        $head = $this->departmentHeadFor($request);

        if ($head !== null) {
            Approval::create([
                'leave_request_id' => $request->id,
                'step_no' => 0,
                'role_slug' => self::STEP_DEPARTMENT,
                'action' => Approval::ACTION_PENDING,
            ]);
        }

        Approval::create([
            'leave_request_id' => $request->id,
            'step_no' => 1,
            'role_slug' => self::STEP,
            'action' => Approval::ACTION_PENDING,
        ]);

        $request->update([
            'current_step' => $head !== null ? 0 : 1,
            'status' => $head !== null
                ? LeaveRequest::STATUS_DEPT_REVIEW
                : LeaveRequest::STATUS_PENDING,
        ]);

        $request->user->notify(new LeaveStatusNotification($request, 'submitted'));
    }

    /**
     * The head who should recommend this application, or null if none should.
     *
     * Null in three cases, all of them the same rule wearing different clothes
     * — a step with nobody to act it is not a step:
     *
     *   · the applicant has no department on record;
     *   · the office has no head assigned;
     *   · the applicant IS their office's head. Nobody reviews their own
     *     application, which is why a head's own leave goes straight to the
     *     Mayor or HR.
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

    /** Whether this application is waiting on its department head right now. */
    public function awaitingDepartmentReview(LeaveRequest $request): bool
    {
        return $request->status === LeaveRequest::STATUS_DEPT_REVIEW
            && ! $request->isFinal();
    }

    /**
     * Whether this user is the head who should act on this application.
     *
     * Deliberately identity-based rather than department-based: holding the
     * permission and working in the office is not the same as being the head
     * the office names, and two people in one office could otherwise both act.
     */
    public function canRecommend(User $user, LeaveRequest $request): bool
    {
        if (! $user->hasPermission(self::DEPARTMENT_PERMISSION)) {
            return false;
        }

        $head = $this->departmentHeadFor($request);

        return $head !== null && (int) $head->id === (int) $user->id;
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

        // An employee may never act on their own application, whatever else
        // they hold. Checked before anything else, because it is the one rule
        // that has no exception at either step.
        if ($request->user_id === $actor->id) {
            throw ValidationException::withMessages(['status' => 'You cannot decide your own leave application.']);
        }

        // Which step is this actor acting at? A head recommends at department
        // level; an authorized officer decides — and may do so even while the
        // application is still sitting at department review, so that one absent
        // head can never strand somebody's leave.
        $atDepartment = $this->awaitingDepartmentReview($request) && ! $this->canDecide($actor);

        if ($atDepartment) {
            if (! $this->canRecommend($actor, $request)) {
                throw ValidationException::withMessages([
                    'status' => 'Only the head of this employee\'s office may recommend this application.',
                ]);
            }

            return $this->recommend($request, $actor, $action, $extra);
        }

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

            // Decided before the head got to it. The department row is closed
            // as skipped rather than left pending, so the timeline says plainly
            // that no recommendation was recorded instead of showing a gap the
            // reader has to interpret.
            $request->approvals()
                ->where('step_no', 0)
                ->where('action', Approval::ACTION_PENDING)
                ->update([
                    'action' => Approval::ACTION_SKIPPED,
                    'comments' => 'Decided before a recommendation was recorded.',
                    'acted_at' => now(),
                ]);

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
                // 7.C's third blank. Free text, and only ever set by a
                // deciding officer — a recommendation does not approve
                // anything, so it cannot fill in what was approved.
                'approved_others' => $extra['approved_others'] ?? null,
                'decided_at' => now(),
            ]);
            $this->credits->deductForApproval($request, $actor);
            $this->finish($request, $actor, 'approved');

            return $request;
        });
    }

    /**
     * Record the department head's recommendation and pass the application on.
     *
     * BOTH OUTCOMES ADVANCE. The step exists so the head is aware and their
     * view is on the record — not so they can end somebody's leave. A head who
     * does not endorse sends it forward with that noted, and the Mayor or HR
     * decides with the comment in front of them.
     *
     * The one action that does not advance is `returned`: that is the employee
     * being asked to fix their own application, which is as true at department
     * level as anywhere else.
     */
    private function recommend(LeaveRequest $request, User $actor, string $action, array $extra): LeaveRequest
    {
        return DB::transaction(function () use ($request, $actor, $action, $extra) {
            $row = Approval::where('leave_request_id', $request->id)
                ->where('step_no', 0)->lockForUpdate()->first();

            if (! $row || $row->action !== Approval::ACTION_PENDING) {
                throw ValidationException::withMessages([
                    'status' => 'This application has already been through department review.',
                ]);
            }

            $row->update([
                'approver_id' => $actor->id,
                'action' => $this->normalizeAction($action),
                'comments' => $extra['comments'] ?? null,
                'signature' => $extra['signature'] ?? $actor->name,
                'acted_at' => now(),
            ]);

            if ($action === 'returned') {
                $row->update(['action' => Approval::ACTION_PENDING, 'acted_at' => null, 'approver_id' => null]);
                $request->update(['status' => LeaveRequest::STATUS_RETURNED]);
                $this->finish($request, $actor, 'returned');

                return $request;
            }

            // Endorsed or not, it goes to the deciding officers.
            $request->update([
                'current_step' => 1,
                'status' => LeaveRequest::STATUS_PENDING,
            ]);

            $this->audit->log('leave_recommended', $request, [], [
                'by' => $actor->name,
                'endorsed' => $action === 'approved',
            ], $actor);

            return $request;
        });
    }

    /**
     * Re-submit a returned request.
     *
     * It goes back to whichever step was still open — a request returned by the
     * head comes back to the head, not past them.
     */
    public function resubmit(LeaveRequest $request, User $actor): void
    {
        if ($request->status !== LeaveRequest::STATUS_RETURNED) {
            throw ValidationException::withMessages(['status' => 'Only returned requests can be resubmitted.']);
        }

        $departmentOpen = $request->approvals()
            ->where('step_no', 0)
            ->where('action', Approval::ACTION_PENDING)
            ->exists();

        $request->update([
            'current_step' => $departmentOpen ? 0 : 1,
            'status' => $departmentOpen
                ? LeaveRequest::STATUS_DEPT_REVIEW
                : LeaveRequest::STATUS_PENDING,
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
