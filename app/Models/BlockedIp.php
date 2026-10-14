<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlockedIp extends Model
{
    use Auditable;
    protected $fillable = [
        'ip', 'reason', 'source', 'blocked_by', 'expires_at', 'active',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'active' => 'boolean',
    ];

    public function blocker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_by');
    }

    public function scopeCurrentlyActive($query)
    {
        return $query->where('active', true)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * Whether this block is keeping anybody out right now.
     *
     * One definition, because there were two. The status badge counted an
     * expired block as lifted while the button next to it counted the same row
     * as active, so a row could read "Lifted" and still offer to unblock.
     */
    public function isInEffect(): bool
    {
        return $this->active && (! $this->expires_at || $this->expires_at->isFuture());
    }
}
