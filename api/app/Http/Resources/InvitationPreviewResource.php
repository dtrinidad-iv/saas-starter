<?php

namespace App\Http\Resources;

use App\Models\OrganizationInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What the invited person sees before accepting.
 *
 * @mixin OrganizationInvitation
 */
class InvitationPreviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'organization' => ['name' => $this->organization->name],
            'invited_by' => $this->inviter?->name,
            'email' => $this->email,
            'role' => $this->role,
            'expires_at' => $this->expires_at,
        ];
    }
}
