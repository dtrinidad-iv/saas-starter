<?php

namespace App\Http\Resources;

use App\Models\OrganizationInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A pending invitation as seen by the inviting organization's members.invite holders.
 *
 * @mixin OrganizationInvitation
 */
class InvitationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'role' => $this->role ? RoleResource::summary($this->role) : null,
            'invited_by' => $this->whenLoaded('inviter', fn () => $this->inviter?->only(['id', 'name'])),
            'expires_at' => $this->expires_at,
            'is_expired' => $this->isExpired(),
            'created_at' => $this->created_at,
        ];
    }
}
