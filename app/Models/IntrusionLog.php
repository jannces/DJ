<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntrusionLog extends Model
{
    protected $fillable = [
        'category', 'severity', 'route', 'method', 'payload_excerpt',
        'matched_rule', 'ip', 'user_agent', 'user_id', 'handled',
    ];

    protected $casts = ['handled' => 'boolean'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
