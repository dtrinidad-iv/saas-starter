<?php

namespace App\Http\Controllers\Api;

use App\Actions\Account\ChangeEmail;
use App\Actions\Account\ChangePassword;
use App\Actions\Account\DeleteAccount;
use App\Actions\Account\UpdateProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\ChangeEmailRequest;
use App\Http\Requests\Account\ChangePasswordRequest;
use App\Http\Requests\Account\DeleteAccountRequest;
use App\Http\Requests\Account\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The logged-in user's own account. Not tied to an organization.
 */
class AccountController extends Controller
{
    public function update(UpdateProfileRequest $request, UpdateProfile $action): UserResource
    {
        return new UserResource($action->handle($request->user(), $request->validated()));
    }

    public function updateEmail(ChangeEmailRequest $request, ChangeEmail $action): UserResource
    {
        return new UserResource($action->handle($request->user(), $request->validated('email'), $this->currentToken($request)));
    }

    public function updatePassword(ChangePasswordRequest $request, ChangePassword $action): Response
    {
        $action->handle($request->user(), $request->validated('password'), $this->currentToken($request));

        return response()->noContent();
    }

    public function destroy(DeleteAccountRequest $request, DeleteAccount $action): Response
    {
        $action->handle($request->user());

        return response()->noContent();
    }

    private function currentToken(Request $request): ?PersonalAccessToken
    {
        $token = $request->user()->currentAccessToken();

        return $token instanceof PersonalAccessToken ? $token : null;
    }
}
