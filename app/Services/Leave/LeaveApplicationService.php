<?php

namespace App\Services\Leave;

use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Security\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Orchestrates filing a leave application (validation + credit guard + workflow init). */
class LeaveApplicationService
{
    public function __construct(
        private readonly WorkingDayCalculator $calculator,
        private readonly LeavePolicyEngine $policy,
        private readonly LeaveCreditService $credits,
        private readonly ApprovalWorkflowService $workflow,
        private readonly AuditLogger $audit,
    ) {
    }

    public function computeWorkingDays(Carbon $start, Carbon $end): float
    {
        return $this->calculator->count($start, $end);
    }

    /**
     * @param  array  $data  validated request payload
     */
    public function submit(User $user, LeaveType $type, array $data): LeaveRequest
    {
        $start = Carbon::parse($data['start_date']);
        $end = Carbon::parse($data['end_date']);
        $dateFiled = Carbon::parse($data['date_filed'] ?? now());
        $workingDays = $this->calculator->count($start, $end);

        if ($workingDays <= 0) {
            throw ValidationException::withMessages([
                'end_date' => 'The selected range contains no working days (weekends and holidays are excluded).',
            ]);
        }

        $result = $this->policy->validate($type, $data, $workingDays, $start, $dateFiled);
        if ($result['errors']) {
            throw ValidationException::withMessages(['policy' => $result['errors']]);
        }

        // Hard credit guard at filing time (never allow filing beyond credits).
        if (! $this->credits->hasSufficientCredits($user, $type, $workingDays)) {
            $balance = $this->credits->sourceBalance($user, $type);
            throw ValidationException::withMessages([
                'leave_type_id' => sprintf('Insufficient %s credits: %.2f available, %.1f requested.',
                    $type->credit_source, $balance?->balance ?? 0, $workingDays),
            ]);
        }

        $profile = $user->employeeProfile;

        return DB::transaction(function () use ($user, $type, $data, $start, $end, $dateFiled, $workingDays, $result, $profile) {
            $request = LeaveRequest::create([
                'reference_no' => LeaveRequest::nextReferenceNo(),
                'user_id' => $user->id,
                'leave_type_id' => $type->id,
                'date_filed' => $dateFiled,
                'start_date' => $start,
                'end_date' => $end,
                'working_days' => $workingDays,
                'details' => $data['details'] ?? [],
                'purpose' => $data['purpose'] ?? null,
                'commutation' => (bool) ($data['commutation'] ?? false),
                'is_late_filing' => $result['requires_late_reason'],
                'late_filing_reason' => $data['late_filing_reason'] ?? null,
                'filing_warnings' => $result['warnings'],
                'office_snapshot' => $profile?->department?->name,
                'position_snapshot' => $profile?->position?->title,
                'salary_snapshot' => $profile?->salary,
                'applicant_signature' => $data['applicant_signature'] ?? $user->name,
                'status' => LeaveRequest::STATUS_PENDING,
            ]);

            $this->workflow->initialize($request, $type);
            $this->audit->log('leave_submitted', $request, [], ['reference_no' => $request->reference_no], $user);

            return $request;
        });
    }

    public function cancel(LeaveRequest $request, User $actor): void
    {
        if (! $request->isCancellable()) {
            throw ValidationException::withMessages(['status' => 'This request can no longer be cancelled.']);
        }

        DB::transaction(function () use ($request, $actor) {
            // If it had already been approved (shouldn't happen pre-final), reverse credits.
            if ($request->status === LeaveRequest::STATUS_APPROVED) {
                $this->credits->reverseDeduction($request, $actor);
            }
            $request->update(['status' => LeaveRequest::STATUS_CANCELLED, 'decided_at' => now()]);
            $this->audit->log('leave_cancelled', $request, [], [], $actor);
        });
    }
}
