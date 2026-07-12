<?php

namespace App\Services\Security;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Append-only audit trail (STRIDE: Repudiation). Records who did what, when,
 * from where, with old/new values. Sensitive keys are always redacted.
 */
class AuditLogger
{
    private const REDACTED_KEYS = [
        'password', 'password_confirmation', 'current_password',
        'token', 'remember_token', 'otp', 'code', 'code_hash', 'secret',
    ];

    public function log(
        string $action,
        ?Model $auditable = null,
        array $oldValues = [],
        array $newValues = [],
        ?User $actor = null,
    ): AuditLog {
        $actor ??= Auth::user();
        $request = app()->runningInConsole() ? null : request();

        return AuditLog::create([
            'user_id' => $actor?->id,
            'role_snapshot' => $actor ? app(\App\Services\Rbac\RbacService::class)->userRoleSlugs($actor)->implode(',') : null,
            'action' => $action,
            'auditable_type' => $auditable ? $auditable::class : null,
            'auditable_id' => $auditable?->getKey(),
            'old_values' => $this->redact($oldValues),
            'new_values' => $this->redact($newValues),
            'ip' => $request?->ip(),
            'user_agent' => substr((string) $request?->userAgent(), 0, 500) ?: null,
            'url' => substr((string) $request?->fullUrl(), 0, 255) ?: null,
        ]);
    }

    private function redact(array $values): ?array
    {
        if ($values === []) {
            return null;
        }
        foreach ($values as $key => $value) {
            if (in_array(strtolower((string) $key), self::REDACTED_KEYS, true)) {
                $values[$key] = '[REDACTED]';
            }
        }

        return $values;
    }
}
