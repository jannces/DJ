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
     * The department review can be decided past by the Mayor or HR so one
     * absent head never strands somebody's leave. The row is closed as this
     * rather than left pending, so the timeline states that no recommendation
     * was recorded instead of showing a gap a reader has to interpret.
     */
    public const ACTION_SKIPPED = 'skipped';

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
