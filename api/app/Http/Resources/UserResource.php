<?php

namespace App\Http\Resources;

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

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified' => $this->hasVerifiedEmail(),
            'two_factor_enabled' => $this->hasTwoFactorEnabled(),
            'current_organization' => $currentOrganization ? new OrganizationResource($currentOrganization) : null,
            'created_at' => $this->created_at,
        ];
    }
}
