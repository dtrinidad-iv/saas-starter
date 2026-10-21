<?php

namespace App\Actions\Auth;

use App\Models\Organization;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Support\DeviceName;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class IssueToken
{
    public function __construct(private readonly Request $request) {}

    /**
     * A new API token for the user, labelled with the device it was issued to
     * (the client's device_name, or one read from the User-Agent). $twoFactor: it
     * was issued after a two-factor code (admin routes need that, recently).
     */
    public function handle(User $user, ?string $deviceName = null, bool $twoFactor = false): string
    {
        $userAgent = $this->request->userAgent();

        $token = $user->createToken($deviceName ?: DeviceName::fromUserAgent($userAgent));
        $token->accessToken->forceFill([
            'ip_address' => $this->request->ip(),
            'user_agent' => $userAgent === null ? null : Str::limit($userAgent, 509),
            'two_factor_at' => $twoFactor ? now() : null,
        ])->save();

        return $token->plainTextToken;
    }

    /**
     * A platform admin's read-only "view as user" token (StartImpersonation): it
     * belongs to $user, names the admin, starts in $organization and expires at
     * $expiresAt. It never passes two-factor, so it can't reach admin routes.
     *
     * @return array{0: string, 1: PersonalAccessToken}
     */
    public function impersonation(User $user, User $admin, Organization $organization, CarbonInterface $expiresAt): array
    {
        $userAgent = $this->request->userAgent();

        $token = $user->createToken('Support view', ['*'], $expiresAt);
        /** @var PersonalAccessToken $accessToken */
        $accessToken = $token->accessToken;
        $accessToken->forceFill([
            'ip_address' => $this->request->ip(),
            'user_agent' => $userAgent === null ? null : Str::limit($userAgent, 509),
            'impersonator_id' => $admin->id,
            'organization_id' => $organization->id,
        ])->save();

        return [$token->plainTextToken, $accessToken];
    }
}
