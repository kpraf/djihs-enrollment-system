<?php

namespace App\Models;

use App\Enums\Ability;
use App\Enums\Role;
use App\Models\Concerns\Auditable;
use App\Services\Auth\RolePermissions;
use Illuminate\Database\Eloquent\Model;

/**
 * One permission a role has. Granting a permission adds a row and taking it
 * away deletes it, so the audit log reads as "added" or "removed".
 */
class RolePermission extends Model
{
    use Auditable;

    protected $fillable = ['role', 'ability'];

    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'ability' => Ability::class,
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => RolePermissions::forget());
        static::deleted(fn () => RolePermissions::forget());
    }
}
