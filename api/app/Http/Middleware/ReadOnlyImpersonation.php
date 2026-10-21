<?php

namespace App\Http\Middleware;

use App\Support\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * In the auth:sanctum group itself, so every logged-in route is covered by default:
 * a platform admin's "view as user" session can only read. Anything but GET/HEAD
 * gets 403 impersonation_read_only, except ending the session and switching which
 * organization it looks at (SwitchOrganization moves only the session).
 */
class ReadOnlyImpersonation
{
    /** Route names a "view as user" session may POST to. */
    private const ALLOWED_ROUTES = ['logout', 'organizations.switch'];

    public function __construct(private readonly Impersonation $impersonation) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->impersonation->active()
            && ! $request->isMethodSafe()
            && ! in_array($request->route()?->getName(), self::ALLOWED_ROUTES, true)) {
            return response()->json([
                'message' => 'You\'re viewing as this user, read-only. Nothing can be changed.',
                'code' => 'impersonation_read_only',
            ], 403);
        }

        return $next($request);
    }
}
