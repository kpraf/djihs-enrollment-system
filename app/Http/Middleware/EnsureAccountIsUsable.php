<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs out anyone whose account was deactivated mid-session, and sends
 * users with an admin-issued password to change it before doing anything else.
 */
class EnsureAccountIsUsable
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        if (! $user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'username' => 'This account has been deactivated. Please contact the ICT Coordinator.',
            ]);
        }

        if ($user->must_change_password && ! $request->routeIs('password.edit', 'password.update', 'logout', 'text-size.update')) {
            return redirect()->route('password.edit');
        }

        return $next($request);
    }
}
