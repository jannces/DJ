<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Approval extends Model
{
    public const ACTION_PENDING = 'pending';
    public const ACTION_APPROVED = 'approved';
    public const ACTION_REJECTED = 'rejected';
    public const ACTION_RETURNED = 'returned';
    public const ACTION_CERTIFIED = 'certified';

    /**
     * A step that was overtaken rather than acted on.
     *
     * Kept for the history written before the department step became a
     * notification: installations carry rows closed this way and the timeline
     * still has to read them.
     */
    public const ACTION_SKIPPED = 'skipped';

    /**
     * Not a step at all — a record that somebody was TOLD.
     *
     * The applicant's department head is informed the moment an application is
     * filed and has nothing to act on: the application goes straight to HR.
     * The row exists for two reasons that a notification alone cannot serve.
     *
     *   · It is the printed form's memory. CSC Form No. 6 box 7.B carries the
     *     head of the applicant's office, and a form reprinted two years later
     *     must name who headed that office ON THE DAY IT WAS FILED, not
     *     whoever heads it now. `signature` holds that snapshot.
     *
     *   · It puts the notification on the employee's own timeline, so the
     *     applicant can see their head was informed rather than having to
     *     trust that they were.
     *
     * It is never pending, so it can never hold an application up.
     */
    public const ACTION_NOTIFIED = 'notified';

    protected $fillable = [
        'leave_request_id', 'step_no', 'role_slug', 'approver_id', 'action',
        'comments', 'days_with_pay', 'days_without_pay', 'certified_balances',
        'signature', 'acted_at',
    ];

    protected $casts = [
        'certified_balances' => 'array',
        'days_with_pay' => 'decimal:1',
        'days_without_pay' => 'decimal:1',
        'acted_at' => 'datetime',
    ];

    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
