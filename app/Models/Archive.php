<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Archive extends Model
{
    protected $fillable = [
        'archivable_type', 'archivable_id', 'snapshot', 'archived_by', 'restored_at',
    ];

    protected $casts = [
        'snapshot' => 'array',
        'restored_at' => 'datetime',
    ];

    public function archiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
    }
}
