<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Records every create, update and delete of the model in audit_logs,
 * together with who did it. Nothing in a controller has to remember to log.
 */
trait Auditable
{
    /** @var list<string> */
    protected static array $auditIgnored = ['created_at', 'updated_at', 'password', 'remember_token', 'lock_version', 'last_login_at'];

    public static function bootAuditable(): void
    {
        static::created(fn (Model $model) => static::writeAudit($model, 'created', [], $model->getAttributes()));

        static::updated(function (Model $model) {
            $changes = $model->getChanges();
            $old = array_intersect_key($model->getOriginal(), $changes);
            static::writeAudit($model, 'updated', $old, $changes);
        });

        static::deleted(fn (Model $model) => static::writeAudit($model, 'deleted', $model->getOriginal(), []));
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    protected static function writeAudit(Model $model, string $event, array $old, array $new): void
    {
        $ignored = array_flip(static::$auditIgnored);
        $old = array_diff_key($old, $ignored);
        $new = array_diff_key($new, $ignored);

        if ($event === 'updated' && $new === []) {
            return;
        }

        AuditLog::create([
            'user_id' => Auth::id(),
            'event' => $event,
            'auditable_type' => $model->getMorphClass(),
            'auditable_id' => $model->getKey(),
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
            'ip_address' => app()->runningInConsole() ? null : request()->ip(),
        ]);
    }
}
