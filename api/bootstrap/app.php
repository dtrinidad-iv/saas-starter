<?php

use App\Http\Middleware\EnsureCurrentOrganization;
use App\Http\Middleware\EnsureEmailIsVerified;
use App\Http\Middleware\EnsurePlatformAdmin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

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

        // A wrong method on an admin route (GET on a POST-only one) would answer 405 and
        // name the supported methods, before any middleware runs: answer the same 404 as
        // an unknown route, so the area isn't revealed (see EnsurePlatformAdmin).
        $exceptions->map(MethodNotAllowedHttpException::class, fn (MethodNotAllowedHttpException $exception) => request()->is('api/admin', 'api/admin/*')
            ? EnsurePlatformAdmin::notFound(request())
            : $exception);
    })->create();
