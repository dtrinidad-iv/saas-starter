<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * For platform admin routes. Not an admin: the same 404 as an unknown route, before
 * route model binding (it's ahead of SubstituteBindings in the priority list), so
 * the API doesn't confirm the area exists or which ids do. An admin also needs two-factor on, and this token must have passed
 * a code within config('admin.two_factor_ttl_minutes') (`platform-admin:action`, for
 * write actions: config('admin.action_two_factor_ttl_minutes')); the codes tell the
 * client what to ask for.
 */
class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next, ?string $level = null): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->isPlatformAdmin()) {
            throw self::notFound($request);
        }

        if (! $user->hasTwoFactorEnabled()) {
            return response()->json([
                'message' => 'Turn on two-factor authentication to use the admin area.',
                'code' => 'two_factor_required',
            ], 403);
        }

        $token = $user->currentAccessToken();
        $passedAt = $token instanceof PersonalAccessToken ? $token->getAttribute('two_factor_at') : null;
        $ttl = (int) config($level === 'action' ? 'admin.action_two_factor_ttl_minutes' : 'admin.two_factor_ttl_minutes');
        $fresh = $passedAt !== null && Carbon::parse($passedAt)->gt(now()->subMinutes($ttl));

        if (! $fresh) {
            return response()->json([
                'message' => 'Enter a code from your authenticator app to continue.',
                'code' => 'two_factor_reauth',
            ], 403);
        }

        return $next($request);
    }

    /**
     * The router's own 404 for an unknown route.
     */
    public static function notFound(Request $request): NotFoundHttpException
    {
        return new NotFoundHttpException(sprintf('The route %s could not be found.', $request->path()));
    }
}
