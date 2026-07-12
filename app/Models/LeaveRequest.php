<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LeaveRequest extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    public const STATUS_PENDING = 'pending';
    public const STATUS_DEPT_REVIEW = 'dept_review';
    public const STATUS_HR_REVIEW = 'hr_review';
    public const STATUS_FINAL_REVIEW = 'final_review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_RETURNED = 'returned';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'reference_no', 'user_id', 'leave_type_id', 'date_filed',
        'start_date', 'end_date', 'working_days', 'details', 'purpose',
        'commutation', 'status', 'current_step', 'is_late_filing',
        'late_filing_reason', 'filing_warnings', 'hr_override', 'hr_override_reason',
        'days_with_pay', 'days_without_pay', 'disapproval_reason',
        'office_snapshot', 'position_snapshot', 'salary_snapshot',
        'applicant_signature', 'decided_at',
    ];

    protected $casts = [
        'date_filed' => 'date',
        'start_date' => 'date',
        'end_date' => 'date',
        'working_days' => 'decimal:1',
        'details' => 'array',
        'commutation' => 'boolean',
        'is_late_filing' => 'boolean',
        'filing_warnings' => 'array',
        'hr_override' => 'boolean',
        'days_with_pay' => 'decimal:1',
        'days_without_pay' => 'decimal:1',
        'salary_snapshot' => 'decimal:2',
        'decided_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(LeaveRequestDocument::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class)->orderBy('step_no');
    }

    public function isFinal(): bool
    {
        return in_array($this->status, [
            self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED,
        ], true);
    }

    public function isCancellable(): bool
    {
        return ! $this->isFinal();
    }

    public function scopeStatus($query, ?string $status)
    {
        return $status ? $query->where('status', $status) : $query;
    }

    public static function nextReferenceNo(): string
    {
        $prefix = 'LV-'.now()->format('Y').'-';
        $last = static::withTrashed()
            ->where('reference_no', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('reference_no');
        $seq = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
    }
}
