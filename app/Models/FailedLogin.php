<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FailedLogin extends Model
{
    protected $fillable = [
        'identifier', 'user_id', 'ip', 'user_agent', 'reason', 'occurred_at',
    ];

    protected $casts = ['occurred_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
