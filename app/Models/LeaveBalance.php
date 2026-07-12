<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveBalance extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'user_id', 'leave_type_id', 'earned', 'used', 'balance', 'last_accrued_period',
    ];

    protected $casts = [
        'earned' => 'decimal:3',
        'used' => 'decimal:3',
        'balance' => 'decimal:3',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }
}
