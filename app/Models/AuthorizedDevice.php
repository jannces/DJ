<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuthorizedDevice extends Model
{
    use Auditable;
    protected $fillable = [
        'ip_address', 'hostname', 'mac_address', 'description', 'status',
        'registered_by', 'last_active_at', 'archived_at',
    ];

    protected $casts = [
        'last_active_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    public function registrar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    public function isOnline(): bool
    {
        return $this->last_active_at !== null
            && $this->last_active_at->gt(now()->subMinutes(5));
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active')->whereNull('archived_at');
    }
}
