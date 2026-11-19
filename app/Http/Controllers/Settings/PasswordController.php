<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class PasswordController extends Controller
{
    /**
     * Show the user's password settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/password', [
            'mustChangePassword' => $request->user()->must_change_password,
        ]);
    }

    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            // Reusing a temporary password would leave it known to whoever issued it.
            'password' => ['required', Password::defaults(), 'confirmed', 'different:current_password'],
        ], [
            'password.different' => 'Choose a new password that is different from your current one.',
        ]);

        $user = $request->user();
        $wasForced = $user->must_change_password;

        $user->update([
            'password' => Hash::make($validated['password']),
            'must_change_password' => false,
        ]);

        return $wasForced
            ? to_route('dashboard')->with('success', 'Password updated. Welcome!')
            : back()->with('success', 'Password updated.');
    }
}
