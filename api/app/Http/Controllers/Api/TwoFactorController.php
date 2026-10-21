<?php

namespace App\Http\Controllers\Api;

use App\Actions\Account\ConfirmTwoFactor;
use App\Actions\Account\DisableTwoFactor;
use App\Actions\Account\RegenerateRecoveryCodes;
use App\Actions\Account\StartTwoFactorSetup;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\ConfirmTwoFactorRequest;
use App\Http\Requests\Account\DisableTwoFactorRequest;
use App\Http\Requests\Account\RegenerateRecoveryCodesRequest;
use App\Http\Requests\Account\StartTwoFactorRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Two-factor authentication on the logged-in user's own account.
 */
class TwoFactorController extends Controller
{
    public function store(StartTwoFactorRequest $request, StartTwoFactorSetup $action): JsonResponse
    {
        return response()->json(['data' => $action->handle($request->user())]);
    }

    public function confirm(ConfirmTwoFactorRequest $request, ConfirmTwoFactor $action): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        $codes = $action->handle(
            $request->user(),
            $request->validated('code'),
            $token instanceof PersonalAccessToken ? $token : null,
        );

        return response()->json(['data' => ['recovery_codes' => $codes]]);
    }

    public function recoveryCodes(RegenerateRecoveryCodesRequest $request, RegenerateRecoveryCodes $action): JsonResponse
    {
        return response()->json(['data' => ['recovery_codes' => $action->handle($request->user())]]);
    }

    public function destroy(DisableTwoFactorRequest $request, DisableTwoFactor $action): Response
    {
        $action->handle($request->user());

        return response()->noContent();
    }
}
