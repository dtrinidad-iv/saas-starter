<?php

namespace App\Http\Resources;

use App\Enums\Permission;
use App\Models\AuditLog;
use App\Support\CurrentOrganization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AuditLog
 */
class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $currentOrganization = app(CurrentOrganization::class);
        // Project names and content only for those who may view projects.
        $hidden = $this->subject_type === 'project' && ! $currentOrganization->can(Permission::ProjectsView);

        return [
            'id' => $this->id,
            'action' => $this->action->value,
            'action_label' => $this->action->label(),
            'actor' => $this->whenLoaded('actor', fn () => $this->actor?->only(['id', 'name'])),
            // A platform admin did it: there's no actor, and the client shows "Platform admin".
            'by_platform_admin' => $this->by_platform_admin,
            'subject' => $this->subject_type === null ? null : [
                'type' => $this->subject_type,
                'id' => $this->subject_id,
                'label' => $hidden ? null : $this->subject_label,
            ],
            'changes' => $hidden ? (object) [] : ($this->changes ?? (object) []),
            'metadata' => $this->metadata ?? (object) [],
            // Personal data: only for owners, not everyone who can read the log.
            'ip_address' => $this->when($currentOrganization->role()?->is_owner === true, $this->ip_address),
            'created_at' => $this->created_at,
        ];
    }
}
