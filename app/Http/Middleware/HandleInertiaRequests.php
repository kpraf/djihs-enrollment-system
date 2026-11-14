<?php

namespace App\Http\Middleware;

use App\Enums\Ability;
use App\Models\SchoolYear;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'username' => $user->username,
                    'email' => $user->email,
                    'role' => $user->role->value,
                    'role_label' => $user->role->label(),
                    'must_change_password' => $user->must_change_password,
                ] : null,
                // The frontend only uses these to hide links. Every route
                // checks the same abilities again on the server.
                'abilities' => $user
                    ? array_values(array_map(fn (Ability $a) => $a->value, array_filter(
                        Ability::cases(), fn (Ability $a) => $user->hasAbility($a),
                    )))
                    : [],
            ],
            'activeSchoolYear' => fn () => $user ? SchoolYear::active()?->only(['id', 'name', 'is_enrollment_open']) : null,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
