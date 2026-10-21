<?php

namespace App\Actions\ProjectChat;

use App\Models\Project;
use App\Models\ProjectChatMessage;
use App\Models\User;

/**
 * Deletes the user's own conversation about the project; other members' chats stay.
 */
class ClearProjectChat
{
    public function handle(Project $project, User $user): void
    {
        ProjectChatMessage::query()->conversation($project, $user)->delete();
    }
}
