<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('users/index', [
            'users' => User::orderBy('last_name')->orderBy('first_name')->get()->map(fn (User $u) => [
                'id' => $u->id,
                'username' => $u->username,
                'first_name' => $u->first_name,
                'last_name' => $u->last_name,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role->value,
                'role_label' => $u->role->label(),
                'is_active' => $u->is_active,
                'last_login_at' => $u->last_login_at?->toIso8601String(),
            ]),
            'roles' => $this->assignableRoles(request()->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        // Usernames are stored in lower case, so compare them that way too.
        $request->merge(['username' => Str::lower(trim((string) $request->input('username')))]);

        $data = $request->validate([
            'username' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9._-]+$/i', Rule::unique('users', 'username')],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', Rule::in(array_column($this->assignableRoles($request->user()), 'value'))],
        ]);

        $password = $this->temporaryPassword();

        try {
            DB::transaction(fn () => User::create([...$data, 'password' => $password, 'must_change_password' => true]));
        } catch (UniqueConstraintViolationException) {
            // Someone created the same username or email a moment ago.
            throw ValidationException::withMessages(['username' => 'That username or email was just taken. Choose another.']);
        }

        return back()
            ->with('success', "Account {$data['username']} created.")
            ->with('credential', ['username' => $data['username'], 'password' => $password, 'reason' => 'created']);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->guardAdminTarget($request->user(), $user);

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user)],
            'role' => ['required', Rule::in(array_column($this->assignableRoles($request->user()), 'value'))],
        ]);

        $refused = DB::transaction(function () use ($user, $data) {
            if ($user->role === Role::Admin && $data['role'] !== Role::Admin->value && $this->isLastAdmin($user)) {
                return true;
            }
            $user->update($data);

            return false;
        });

        return $refused
            ? back()->with('error', 'You cannot change the role of the only administrator.')
            : back()->with('success', 'Account updated.');
    }

    /**
     * Sets the account to the state the button asked for. Flipping the current
     * state instead would let two staff clicking "Deactivate" at the same time
     * cancel each other out.
     */
    public function setActive(Request $request, User $user): RedirectResponse
    {
        $this->guardAdminTarget($request->user(), $user);
        $active = $request->validate(['active' => ['required', 'boolean']])['active'];
        $active = filter_var($active, FILTER_VALIDATE_BOOLEAN);

        if ($user->is($request->user()) && ! $active) {
            return back()->with('error', 'You cannot deactivate your own account.');
        }

        $refused = DB::transaction(function () use ($user, $active) {
            $user->refresh();
            if (! $active && $user->role === Role::Admin && $this->isLastAdmin($user)) {
                return true;
            }
            $user->update(['is_active' => $active]);

            return false;
        });

        if ($refused) {
            return back()->with('error', 'You cannot deactivate the only administrator.');
        }

        return back()->with('success', $user->is_active ? 'Account activated.' : 'Account deactivated.');
    }

    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        $this->guardAdminTarget($request->user(), $user);

        $password = $this->temporaryPassword();
        $user->update(['password' => $password, 'must_change_password' => true]);

        return back()
            ->with('success', "Password reset for {$user->username}.")
            ->with('credential', ['username' => $user->username, 'password' => $password, 'reason' => 'reset']);
    }

    /**
     * Only administrators can create or change administrator accounts.
     *
     * @return list<array{value: string, label: string}>
     */
    private function assignableRoles(User $actor): array
    {
        return array_values(array_filter(
            Role::options(),
            fn ($r) => $actor->role === Role::Admin || $r['value'] !== Role::Admin->value,
        ));
    }

    private function guardAdminTarget(User $actor, User $target): void
    {
        abort_if($target->role === Role::Admin && $actor->role !== Role::Admin, 403, 'Only administrators can change administrator accounts.');
    }

    /**
     * Locks every active admin row so two admins cannot remove each other at
     * the same moment and leave the school with none. Call inside a transaction.
     */
    private function isLastAdmin(User $user): bool
    {
        $admins = User::where('role', Role::Admin)->where('is_active', true)->lockForUpdate()->pluck('id');

        return $admins->reject(fn ($id) => $id === $user->id)->isEmpty();
    }

    private function temporaryPassword(): string
    {
        // Letters and digits only, so it is easy to read aloud or copy by hand.
        return Str::password(12, symbols: false);
    }
}
