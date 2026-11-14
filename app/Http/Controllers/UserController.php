<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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
        $data = $request->validate([
            'username' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9._-]+$/i', Rule::unique('users', 'username')],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', Rule::in(array_column($this->assignableRoles($request->user()), 'value'))],
        ]);

        $password = $this->temporaryPassword();

        User::create([
            ...$data,
            'username' => Str::lower($data['username']),
            'password' => $password,
            'must_change_password' => true,
        ]);

        return back()->with('success', "Account created. Temporary password: {$password}");
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

        if ($user->role === Role::Admin && $data['role'] !== Role::Admin->value && $this->isLastAdmin($user)) {
            return back()->with('error', 'You cannot change the role of the only administrator.');
        }

        $user->update($data);

        return back()->with('success', 'Account updated.');
    }

    public function toggleActive(Request $request, User $user): RedirectResponse
    {
        $this->guardAdminTarget($request->user(), $user);

        if ($user->is($request->user())) {
            return back()->with('error', 'You cannot deactivate your own account.');
        }

        if ($user->is_active && $user->role === Role::Admin && $this->isLastAdmin($user)) {
            return back()->with('error', 'You cannot deactivate the only administrator.');
        }

        $user->update(['is_active' => ! $user->is_active]);

        return back()->with('success', $user->is_active ? 'Account activated.' : 'Account deactivated.');
    }

    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        $this->guardAdminTarget($request->user(), $user);

        $password = $this->temporaryPassword();
        $user->update(['password' => $password, 'must_change_password' => true]);

        return back()->with('success', "Password reset for {$user->username}. Temporary password: {$password}");
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

    private function isLastAdmin(User $user): bool
    {
        return ! User::where('role', Role::Admin)->where('is_active', true)->whereKeyNot($user->id)->exists();
    }

    private function temporaryPassword(): string
    {
        // Letters and digits only, so it is easy to read aloud or copy by hand.
        return Str::password(12, symbols: false);
    }
}
