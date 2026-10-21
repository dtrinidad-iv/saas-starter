<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Support\DeviceName;
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
}
