<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OtpCode extends Model
{
    /** The code was minted and nothing has been heard about it yet. */
    public const DELIVERY_PENDING = 'pending';

    /** Handed to the mailer without complaint. */
    public const DELIVERY_SENT = 'sent';

    /** The mailer refused it — the person will never receive this code. */
    public const DELIVERY_FAILED = 'failed';

    /** Read out by an administrator instead of being e-mailed. */
    public const DELIVERY_BYPASS = 'bypass';

    protected $fillable = [
        'user_id', 'code_hash', 'purpose', 'expires_at', 'consumed_at', 'attempts', 'ip',
        'delivery_status', 'delivery_error', 'issued_by_id',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The administrator who read this code out, when it was a bypass. */
    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_id');
    }

    public function deliveryFailed(): bool
    {
        return $this->delivery_status === self::DELIVERY_FAILED;
    }

    public function wasBypass(): bool
    {
        return $this->delivery_status === self::DELIVERY_BYPASS;
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null
            && $this->expires_at->isFuture()
            && $this->attempts < 5;
    }
}
