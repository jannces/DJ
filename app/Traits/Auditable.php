<?php

namespace App\Traits;

use App\Services\Security\AuditLogger;

/**
 * Attach to any model whose CRUD must land in the audit trail.
 * Captures changed attributes (old vs new) on update.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function ($model) {
            app(AuditLogger::class)->log('created', $model, [], $model->getAttributes());
        });

        static::updated(function ($model) {
            $changes = $model->getChanges();
            unset($changes['updated_at']);
            if ($changes === []) {
                return;
            }
            $old = array_intersect_key($model->getOriginal(), $changes);
            app(AuditLogger::class)->log('updated', $model, $old, $changes);
        });

        static::deleted(function ($model) {
            app(AuditLogger::class)->log('deleted', $model, $model->getAttributes(), []);
        });
    }
}
