<?php

namespace App\Http\Controllers;

use App\Enums\Ability;
use App\Enums\Role;
use App\Models\RolePermission;
use App\Models\RoleSetting;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Roles and Permissions: what each role may do, and retiring a role the
 * school no longer uses. Every change is written to the audit log by the
 * RolePermission and RoleSetting models.
 */
class RoleController extends Controller
{
    /** The order permission groups are listed in. */
    private const GROUPS = ['Enrollment', 'Learner records', 'Sections', 'Teaching', 'Reports', 'Administration'];

    public function index(): Response
    {
        $accounts = User::query()->selectRaw('role, count(*) as total')->groupBy('role')->pluck('total', 'role');
        $values = fn (array $abilities) => array_map(fn (Ability $a) => $a->value, $abilities);

        $abilities = Ability::cases();
        usort($abilities, fn (Ability $a, Ability $b) => [array_search($a->group(), self::GROUPS, true), array_search($a, Ability::cases(), true)]
            <=> [array_search($b->group(), self::GROUPS, true), array_search($b, Ability::cases(), true)]);

        return Inertia::render('roles/index', [
            'roles' => array_map(fn (Role $r) => [
                'value' => $r->value,
                'label' => $r->label(),
                'accounts' => (int) ($accounts[$r->value] ?? 0),
                'is_retired' => $r->isRetired(),
                'can_retire' => $r !== Role::Admin,
                'abilities' => $values($r->abilities()),
                'defaults' => $values($r->defaultAbilities()),
                'locked' => $values($r->lockedAbilities()),
            ], Role::cases()),
            'abilities' => array_map(fn (Ability $a) => [
                'value' => $a->value, 'label' => $a->label(), 'group' => $a->group(), 'note' => $a->note(),
            ], $abilities),
        ]);
    }

    /**
     * Applies only the cells that were changed, so two administrators working
     * on different permissions at the same time don't undo each other.
     */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'changes' => ['required', 'array', 'min:1'],
            'changes.*.role' => ['required', Rule::enum(Role::class)],
            'changes.*.ability' => ['required', Rule::enum(Ability::class)],
            'changes.*.granted' => ['required', 'boolean'],
        ]);

        $applied = DB::transaction(function () use ($data) {
            $applied = 0;
            foreach ($data['changes'] as $change) {
                $applied += $this->set(Role::from($change['role']), Ability::from($change['ability']), (bool) $change['granted']) ? 1 : 0;
            }

            return $applied;
        });

        return back()->with('success', $applied === 0 ? 'Nothing to change: those permissions were already set.'
            : ($applied === 1 ? 'Saved 1 change.' : "Saved {$applied} changes."));
    }

    /** Puts every role's permissions back to what the system started with. */
    public function reset(): RedirectResponse
    {
        $applied = DB::transaction(function () {
            $applied = 0;
            foreach (Role::cases() as $role) {
                if ($role->isRetired()) {
                    continue;
                }
                foreach (Ability::cases() as $ability) {
                    $applied += $this->set($role, $ability, in_array($ability, $role->defaultAbilities(), true)) ? 1 : 0;
                }
            }

            return $applied;
        });

        return back()->with('success', $applied === 0 ? 'Every role already has its default permissions.' : 'Default permissions are back.');
    }

    /**
     * Retires a role: no account can be given it any more, and the accounts
     * that have it move to another role so nobody loses access.
     */
    public function retire(Request $request, Role $role): RedirectResponse
    {
        abort_if($role === Role::Admin, 403, 'The Administrator role can\'t be retired.');
        if ($role->isRetired()) {
            return back()->with('error', "{$role->label()} is already retired.");
        }

        $accounts = User::where('role', $role)->count();
        $choices = array_values(array_filter(
            array_column(Role::activeOptions(), 'value'),
            fn (string $r) => $r !== $role->value && $r !== Role::Admin->value,
        ));
        $data = $request->validate([
            'move_to' => [$accounts > 0 ? 'required' : 'nullable', Rule::in($choices)],
        ], ['move_to.required' => 'Choose the role these accounts move to.']);

        $moved = DB::transaction(function () use ($role, $data) {
            $users = User::where('role', $role)->lockForUpdate()->get();
            // One save per account, so each move is in the audit log.
            foreach ($users as $user) {
                $user->update(['role' => $data['move_to']]);
            }
            $this->ensureSaved($role);
            RoleSetting::where('role', $role)->first()->update(['is_retired' => true]);

            return $users->count();
        });

        $to = $moved ? ' '.($moved === 1 ? '1 account' : "{$moved} accounts").' moved to '.Role::from($data['move_to'])->label().'.' : '';

        return back()->with('success', "{$role->label()} is retired.{$to}");
    }

    public function restore(Role $role): RedirectResponse
    {
        $this->ensureSaved($role);
        RoleSetting::where('role', $role)->first()->update(['is_retired' => false]);

        return back()->with('success', "{$role->label()} can be given to accounts again.");
    }

    /**
     * A role added to the code later has no saved permissions yet: save its
     * defaults first, so changing one permission doesn't drop the rest.
     */
    private function ensureSaved(Role $role): void
    {
        if (RoleSetting::where('role', $role)->exists()) {
            return;
        }
        RoleSetting::create(['role' => $role, 'is_retired' => false]);
        foreach ($role->defaultAbilities() as $ability) {
            RolePermission::firstOrCreate(['role' => $role, 'ability' => $ability]);
        }
    }

    /** Grants or removes one permission. Returns whether anything changed. */
    private function set(Role $role, Ability $ability, bool $granted): bool
    {
        $this->ensureSaved($role);
        if (in_array($ability, $role->lockedAbilities(), true) && ! $granted) {
            throw ValidationException::withMessages([
                'changes' => "\"{$ability->label()}\" always stays on for the {$role->label()}, so nobody is locked out.",
            ]);
        }
        if ($role->isRetired()) {
            throw ValidationException::withMessages(['changes' => "{$role->label()} is retired. Restore it before changing its permissions."]);
        }

        $existing = RolePermission::where('role', $role)->where('ability', $ability)->lockForUpdate()->first();
        if ($granted && ! $existing) {
            RolePermission::create(['role' => $role, 'ability' => $ability]);

            return true;
        }
        if (! $granted && $existing) {
            $existing->delete();

            return true;
        }

        return false;
    }
}
