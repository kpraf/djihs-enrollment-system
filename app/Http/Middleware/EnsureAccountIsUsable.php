<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs out anyone whose account was deactivated mid-session, and sends
 * users with an admin-issued password to change it before doing anything else,
 * then has new staff accept the confidentiality agreement.
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

        if ($user->must_change_password) {
            return $request->routeIs('password.edit', 'password.update', 'logout', 'text-size.update')
                ? $next($request)
                : redirect()->route('password.edit');
        }

        // Then staff accept the confidentiality agreement once, before they
        // can see any learner's personal data. Checked only after the
        // password step, so the two never send each other back and forth.
        if ($user->privacy_agreed_at === null && ! $request->routeIs('privacy.*', 'logout', 'text-size.update')) {
            return redirect()->route('privacy.agreement');
        }

        return $next($request);
    }
}
