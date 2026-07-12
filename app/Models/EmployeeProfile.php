<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeProfile extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'user_id', 'employee_no', 'first_name', 'middle_name', 'last_name',
        'gender', 'civil_status', 'birth_date', 'contact_no', 'address',
        'salary', 'department_id', 'position_id', 'employment_status',
        'date_hired', 'signature_path', 'is_solo_parent',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'date_hired' => 'date',
        'salary' => 'decimal:2',
        'is_solo_parent' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} ".($this->middle_name ? "{$this->middle_name} " : '').$this->last_name);
    }
}
