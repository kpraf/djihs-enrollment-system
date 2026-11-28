<?php

use App\Http\Middleware\EnsureAccountIsUsable;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Render terminates HTTPS at its load balancer and forwards plain HTTP.
        $middleware->trustProxies(at: '*');

        $middleware->web(append: [
            EnsureAccountIsUsable::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // 419 Page Expired: a page was left open past the 30-minute session (or
        // the laptop slept), so its security token no longer matches. Instead
        // of a bare error box, go back with a message so the person can retry.
        // 303 so the browser follows with GET after a PUT or DELETE.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            if ($response->getStatusCode() !== 419) {
                return $response;
            }
            if ($request->is('logout')) {
                return redirect()->route('login', status: 303);
            }
            if ($request->is('login')) {
                return redirect()->route('login', status: 303)
                    ->with('error', 'The sign-in page was open too long. Please sign in again.');
            }
            if (! $request->user()) {
                return redirect()->route('login', status: 303)
                    ->with('error', 'You were signed out after 30 minutes without activity. Please sign in again.');
            }

            return back(303)->with('error', 'This page was out of date, so nothing was saved. Please try again.');
        });
    })->create();
