<?php

namespace App\Http\Resources;

use App\Models\Project;
use App\Support\Impersonation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Project
 */
class ProjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // A platform admin viewing as the user sees the name, never the content.
        $contentHidden = app(Impersonation::class)->active();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $contentHidden ? null : $this->description,
            'content_hidden' => $contentHidden,
            'creator' => $this->whenLoaded('creator', fn () => $this->creator?->only(['id', 'name'])),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            // What the requesting user may do, so the frontend doesn't repeat the policy.
            'can' => [
                'update' => $request->user()->can('update', $this->resource),
                'delete' => $request->user()->can('delete', $this->resource),
                'chat' => $request->user()->can('chat', $this->resource),
            ],
        ];
    }
}
