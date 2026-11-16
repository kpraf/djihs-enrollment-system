<?php

namespace App\Services\Audit;

use App\Models\AuditLog;

/**
 * Lets a long batch, such as a workbook import, write its audit entries a
 * couple of hundred at a time instead of one query per change. Nothing is
 * left out: every entry is still written, inside the same transaction.
 * A dry run that will be rolled back anyway can skip them altogether.
 */
final class AuditBuffer
{
    private const BATCH = 200;

    private static ?string $mode = null;

    /** @var list<array<string, mixed>> */
    private static array $rows = [];

    /**
     * Runs $work with audit entries collected and written in batches.
     * Call it inside the transaction that does the work.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function batched(callable $work): mixed
    {
        return self::run('batched', $work, flush: true);
    }

    /**
     * Runs $work without writing audit entries. Only for work that is rolled
     * back afterwards, like checking a file before importing it.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function skipped(callable $work): mixed
    {
        return self::run('skipped', $work, flush: false);
    }

    /**
     * Called for every audit entry. Returns true when the buffer took it.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function take(array $attributes): bool
    {
        if (self::$mode === 'skipped') {
            return true;
        }
        if (self::$mode !== 'batched') {
            return false;
        }

        // Fill a model so the old and new values are encoded exactly as
        // AuditLog::create would, then keep the raw row for a bulk insert.
        $log = new AuditLog($attributes);
        $log->created_at = now();
        self::$rows[] = $log->getAttributes();

        if (count(self::$rows) >= self::BATCH) {
            self::flush();
        }

        return true;
    }

    private static function run(string $mode, callable $work, bool $flush): mixed
    {
        self::$mode = $mode;
        self::$rows = [];

        try {
            $result = $work();
            if ($flush) {
                self::flush();
            }

            return $result;
        } finally {
            self::$mode = null;
            self::$rows = [];
        }
    }

    private static function flush(): void
    {
        if (self::$rows !== []) {
            AuditLog::insert(self::$rows);
            self::$rows = [];
        }
    }
}
