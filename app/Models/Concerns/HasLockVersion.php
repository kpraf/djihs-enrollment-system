<?php

namespace App\Models\Concerns;

use App\Exceptions\StaleRecordException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Optimistic locking. Every save bumps lock_version. An edit form carries the
 * version it was loaded with, and updateFromVersion() refuses to save if the
 * row has moved on since, so two staff editing one learner cannot silently
 * overwrite each other.
 */
trait HasLockVersion
{
    public static function bootHasLockVersion(): void
    {
        static::updating(function (Model $model) {
            $model->lock_version = (int) $model->getOriginal('lock_version') + 1;
        });
    }

    /**
     * Locks the row, checks the version, applies the change and saves.
     *
     * @param  callable(static): void  $apply
     *
     * @throws StaleRecordException
     */
    public static function updateFromVersion(int|string $id, int $expectedVersion, callable $apply): static
    {
        return DB::transaction(function () use ($id, $expectedVersion, $apply) {
            /** @var static $model */
            $model = static::query()->lockForUpdate()->findOrFail($id);

            if ((int) $model->lock_version !== $expectedVersion) {
                throw new StaleRecordException;
            }

            $apply($model);
            $model->save();

            return $model;
        });
    }
}
