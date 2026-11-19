<?php

namespace App\Services\Auth;

use App\Enums\Ability;
use App\Enums\Role;
use App\Models\RolePermission;
use App\Models\RoleSetting;
use Illuminate\Database\QueryException;

/**
 * Which role may do what, as saved on the Roles and Permissions page.
 *
 * Read from the database once per request (it is bound as a scoped
 * instance) and forgotten whenever a permission or role setting is saved,
 * so a change applies from the next click. A role that was never saved
 * uses Role::defaultAbilities().
 */
class RolePermissions
{
    /** @var array<string, list<Ability>>|null role value => abilities */
    private ?array $abilities = null;

    /** @var array<string, bool> role value => retired */
    private array $retired = [];

    /** @return list<Ability> */
    public function abilities(Role $role): array
    {
        $this->load();

        return $this->abilities[$role->value] ?? $role->defaultAbilities();
    }

    public function isRetired(Role $role): bool
    {
        $this->load();

        return $this->retired[$role->value] ?? false;
    }

    /** Called when a permission or role setting changes. */
    public static function forget(): void
    {
        app()->forgetInstance(self::class);
    }

    private function load(): void
    {
        if ($this->abilities !== null) {
            return;
        }

        $this->abilities = [];
        try {
            $settings = RoleSetting::all();
            $saved = RolePermission::all()->groupBy(fn (RolePermission $p) => $p->role->value);
        } catch (QueryException) {
            // Before the tables exist, e.g. part way through the first migration.
            return;
        }

        foreach ($settings as $setting) {
            $this->retired[$setting->role->value] = $setting->is_retired;
            // A role with a settings row uses exactly what was saved, even if that is nothing.
            $this->abilities[$setting->role->value] = ($saved[$setting->role->value] ?? collect())
                ->map(fn (RolePermission $p) => $p->ability)
                ->sortBy(fn (Ability $a) => array_search($a, Ability::cases(), true))
                ->values()
                ->all();
        }
    }
}
