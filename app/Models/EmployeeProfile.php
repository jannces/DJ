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
        'date_hired', 'signature_path', 'signature_hash', 'signature_uploaded_at',
        'is_solo_parent',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'date_hired' => 'date',
        'salary' => 'decimal:2',
        'is_solo_parent' => 'boolean',
    ];

    /** The shape a generated employee number takes. */
    public const NUMBER_PREFIX = 'EMP-';

    /**
     * The next employee number in the sequence.
     *
     * Offered so that adding an account does not begin with opening the
     * employee list, sorting it, and reading the last number off the bottom --
     * which is what it took, and is how two people end up sharing one.
     *
     * A suggestion, not an allocation: the field stays editable, because an
     * office that numbers its own way has to be able to. Whatever is submitted
     * is still checked for uniqueness by the controller.
     *
     * Numbers outside this shape are ignored rather than parsed. An LGU that
     * carried its own format across from paper keeps it, and the generated
     * sequence runs alongside without colliding, since a collision could only
     * come from a number that already matches the pattern -- and those are
     * exactly the ones counted here.
     */
    public static function nextEmployeeNo(): string
    {
        // Compared as numbers, not as text: 'EMP-9' sorts after 'EMP-10'.
        $highest = static::withoutGlobalScopes()
            ->where('employee_no', 'like', self::NUMBER_PREFIX.'%')
            ->pluck('employee_no')
            ->map(fn ($number) => (int) substr($number, strlen(self::NUMBER_PREFIX)))
            ->max() ?? 0;

        return self::NUMBER_PREFIX.str_pad((string) ($highest + 1), 4, '0', STR_PAD_LEFT);
    }

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
