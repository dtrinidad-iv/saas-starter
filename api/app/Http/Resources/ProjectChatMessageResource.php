<?php

namespace App\Http\Resources;

use App\Models\ProjectChatMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProjectChatMessage
 */
class ProjectChatMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role,
            'content' => $this->content,
            'status' => $this->status,
            'model' => $this->model,
            'created_at' => $this->created_at,
        ];
    }
}
