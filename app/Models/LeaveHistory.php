<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveHistory extends Model
{
    protected $table = 'leave_history';

    protected $fillable = [
        'user_id', 'leave_type_id', 'leave_request_id', 'entry_type',
        'days', 'balance_after', 'period', 'remarks', 'actor_id',
    ];

    protected $casts = [
        'days' => 'decimal:3',
        'balance_after' => 'decimal:3',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
