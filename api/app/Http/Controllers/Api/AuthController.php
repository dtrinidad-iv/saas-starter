<?php

namespace App\Http\Controllers\Api;

use App\Actions\Admin\EndImpersonation;
use App\Actions\Auth\LoginUser;
use App\Actions\Auth\RegisterUser;
use App\Http\Controllers\Api\Concerns\RespondsWithLogin;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\PersonalAccessToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuthController extends Controller
{
    use RespondsWithLogin;

    public function register(RegisterRequest $request, RegisterUser $action): JsonResponse
    {
        return $this->loginResponse($action->handle($request->validated()), 201);
    }

    public function login(LoginRequest $request, LoginUser $action): JsonResponse
    {
        return $this->loginResponse($action->handle($request->validated()));
    }

    // In a platform admin's "view as user" session, this is Stop: it's logged as theirs.
    public function logout(Request $request, EndImpersonation $endImpersonation): Response
    {
        $token = $request->user()->currentAccessToken();

        if ($token instanceof PersonalAccessToken && $token->isImpersonation()) {
            $endImpersonation->handle($token);
        } else {
            $token->delete();
        }

        return response()->noContent();
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }
}
