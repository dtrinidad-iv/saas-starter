<?php

use App\Http\Middleware\EnsureCurrentOrganization;
use App\Http\Middleware\EnsureEmailIsVerified;
use App\Http\Middleware\EnsurePlatformAdmin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // No web login page: API guests get a JSON 401 instead of a redirect to route('login').
        $middleware->redirectGuestsTo(fn (Request $request) => null);

        $middleware->alias([
            'organization' => EnsureCurrentOrganization::class,
            'verified' => EnsureEmailIsVerified::class,
            'platform-admin' => EnsurePlatformAdmin::class,
        ]);

        // Non-admins get 404 before {user} / {organization} are looked up, so ids can't be probed.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: EnsurePlatformAdmin::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
