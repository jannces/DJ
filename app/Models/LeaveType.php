<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveType extends Model
{
    use Auditable, HasFactory;

    public const SOURCE_VACATION = 'vacation';
    public const SOURCE_SICK = 'sick';

    /**
     * The two categories that pay money rather than grant an absence.
     *
     * Named, because both are now branched on in three services and a typo in
     * a bare string would silently route a retirement down the ordinary
     * absence path -- which is the defect these constants were introduced to
     * fix, and the one least likely to be noticed.
     */
    public const CATEGORY_TERMINAL = 'terminal';
    public const CATEGORY_MONETIZATION = 'monetization';

    /** True when this type is commuted to cash instead of taken as days off. */
    public function isCommuted(): bool
    {
        return in_array($this->category, [self::CATEGORY_TERMINAL, self::CATEGORY_MONETIZATION], true);
    }

    protected $fillable = [
        'code', 'name', 'category', 'max_days', 'counts_calendar_days',
        'deductible', 'credit_source',
        'requires_medical_after_days', 'filing_deadline_days', 'deadline_is_hard',
        'detail_schema', 'required_documents', 'approval_flow',
        'annual_reset', 'expires', 'is_custom', 'active', 'description',
    ];

    protected $casts = [
        'max_days' => 'decimal:1',
        'counts_calendar_days' => 'boolean',
        'deductible' => 'boolean',
        'deadline_is_hard' => 'boolean',
        'detail_schema' => 'array',
        'required_documents' => 'array',
        'approval_flow' => 'array',
        'annual_reset' => 'boolean',
        'expires' => 'boolean',
        'is_custom' => 'boolean',
        'active' => 'boolean',
    ];

    public function requests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /** Ordered workflow steps; defaults to the standard CSC chain. */
    public function workflowSteps(): array
    {
        return $this->approval_flow ?: ['department_head', 'hr', 'mayor'];
    }
}
