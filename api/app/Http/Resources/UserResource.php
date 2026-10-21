<?php

namespace App\Http\Resources;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $currentOrganization = $this->activeOrganization();
        $token = $this->currentAccessToken();
        $impersonation = $token instanceof PersonalAccessToken && $token->isImpersonation() ? $token : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified' => $this->hasVerifiedEmail(),
            'two_factor_enabled' => $this->hasTwoFactorEnabled(),
            'is_platform_admin' => $this->isPlatformAdmin(),
            // A platform admin's read-only "view as user" session: only their name, never their email.
            'impersonated_by' => $impersonation === null ? null : [
                'name' => $impersonation->impersonator?->name,
                'expires_at' => $impersonation->expires_at,
            ],
            'current_organization' => $currentOrganization ? new OrganizationResource($currentOrganization) : null,
            'created_at' => $this->created_at,
        ];
    }
}
