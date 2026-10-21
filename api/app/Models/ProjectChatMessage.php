<?php

namespace App\Models;

use App\Enums\ChatMessageRole;
use App\Enums\ChatMessageStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message of a user's private AI chat about a project. Only that user sees it.
 * Not Auditable: it's personal and changes with every answer. All columns are set
 * by actions (no mass assignment).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $project_id
 * @property int $user_id
 * @property ChatMessageRole $role
 * @property string $content
 * @property string|null $model
 * @property ChatMessageStatus $status
 */
class ProjectChatMessage extends Model
{
    use BelongsToOrganization;

    protected function casts(): array
    {
        return [
            'role' => ChatMessageRole::class,
            'status' => ChatMessageStatus::class,
        ];
    }

    /**
     * One user's conversation about one project.
     *
     * @param  Builder<self>  $query
     */
    public function scopeConversation(Builder $query, Project $project, User $user): void
    {
        $query->where('project_id', $project->id)->where('user_id', $user->id);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
