<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\StartImpersonation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminActionRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Platform admin: start a read-only "view as user" session, with a reason. Access is
 * the route's platform-admin:action middleware. The client keeps the admin's own
 * token aside, uses this one (GET /api/me shows who it's viewing as), and ends it
 * with POST /api/logout.
 */
class ImpersonationController extends Controller
{
    public function store(AdminActionRequest $request, User $user, StartImpersonation $action): JsonResponse
    {
        $session = $action->handle($request->user(), $user, $request->reason());

        return response()->json([
            'token' => $session['token'],
            'expires_at' => $session['expires_at'],
        ], 201);
    }
}
