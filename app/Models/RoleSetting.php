<?php

namespace App\Models;

use App\Enums\Role;
use App\Models\Concerns\Auditable;
use App\Services\Auth\RolePermissions;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-role settings: whether the role is retired. Its row also marks the
 * role's permissions as saved, so an empty list means "nothing", not "defaults".
 */
class RoleSetting extends Model
{
    use Auditable;

    protected $fillable = ['role', 'is_retired'];

    /** @var array<string, mixed> */
    protected $attributes = ['is_retired' => false];

    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'is_retired' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => RolePermissions::forget());
        static::deleted(fn () => RolePermissions::forget());
    }
}
