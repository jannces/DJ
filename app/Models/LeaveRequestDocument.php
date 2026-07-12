<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRequestDocument extends Model
{
    protected $fillable = [
        'leave_request_id', 'type', 'original_name', 'path',
        'hash', 'size', 'mime', 'uploaded_by',
    ];

    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
