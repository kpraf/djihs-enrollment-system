<?php

namespace App\Services\Privacy;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Records who looked at or downloaded personal data, alongside the change
 * history in audit_logs, so the school can answer "who has seen this
 * learner's record?" as the Data Privacy Act expects.
 */
class AccessLog
{
    public const VIEWED = 'viewed';

    public const EXPORTED = 'exported';

    /** Records that aren't a single row, such as a full backup. */
    public const BACKUP = 'backup';

    public const USER_LIST = 'user-list';

    public const AUDIT_LOG = 'audit-log';

    public static function viewed(Request $request, Model $record, string $what): void
    {
        $recent = AuditLog::where('event', self::VIEWED)
            ->where('user_id', $request->user()?->id)
            ->where('auditable_type', $record->getMorphClass())
            ->where('auditable_id', $record->getKey())
            ->where('created_at', '>=', now()->subMinutes(config('privacy.view_log_minutes')))
            ->exists();

        if (! $recent) {
            self::write($request, self::VIEWED, $record->getMorphClass(), (int) $record->getKey(), $what);
        }
    }

    public static function exported(Request $request, Model|string $record, string $what): void
    {
        $record instanceof Model
            ? self::write($request, self::EXPORTED, $record->getMorphClass(), (int) $record->getKey(), $what)
            : self::write($request, self::EXPORTED, $record, 0, $what);
    }

    private static function write(Request $request, string $event, string $type, int $id, string $what): void
    {
        AuditLog::create([
            'user_id' => $request->user()?->id,
            'event' => $event,
            'auditable_type' => $type,
            'auditable_id' => $id,
            'new_values' => ['what' => $what],
            'ip_address' => $request->ip(),
        ]);
    }
}
