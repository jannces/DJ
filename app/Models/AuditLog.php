<?php

namespace App\Models;

use App\Support\AuditNarrator;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    protected $fillable = [
        'user_id', 'role_snapshot', 'action', 'auditable_type', 'auditable_id',
        'old_values', 'new_values', 'ip', 'user_agent', 'url',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ------------------------------------------------------------- for reading
    //
    // The columns above are what the trail records. These are the same thing
    // written for the person who has to read it -- an HR officer, not a
    // programmer. See AuditNarrator: nothing is recomputed or hidden, the
    // wording is simply not JSON.

    /** "Account block lifted", not `account_unblocked`. */
    protected function actionLabel(): Attribute
    {
        return Attribute::get(fn () => AuditNarrator::action($this->action));
    }

    /** "System admin", not `system-admin`. */
    protected function roleLabel(): Attribute
    {
        return Attribute::get(fn () => AuditNarrator::role($this->role_snapshot));
    }

    /** "User account — Noly J. Macarubbo", not "User 1". */
    protected function targetLabel(): Attribute
    {
        return Attribute::get(fn () => AuditNarrator::target($this->auditable_type, $this->auditable_id));
    }

    /**
     * Only the fields that moved, as [label, from, to].
     *
     * @return list<array{label: string, from: ?string, to: string}>
     */
    protected function changeList(): Attribute
    {
        return Attribute::get(fn () => AuditNarrator::changes($this));
    }
}
