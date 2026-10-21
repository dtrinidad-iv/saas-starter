<?php

namespace App\Actions\ProjectChat;

use App\Ai\AiBudget;
use App\Ai\AiStreamSlots;
use App\Ai\Exceptions\AiBudgetExceeded;
use App\Ai\Prompts\ProjectChatPrompt;
use App\Enums\ChatMessageRole;
use App\Enums\ChatMessageStatus;
use App\Http\Responses\AiEventStream;
use App\Models\Project;
use App\Models\ProjectChatMessage;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Throwable;

/**
 * Saves the user's question with an empty answer after it, then streams the answer
 * into that row when it ends: complete, stopped (the user cancelled; the text so far)
 * or failed. Saving the answer row up front keeps the order right when the next
 * question arrives before the server notices a stop, and lets Clear remove it. Until
 * it ends it reads as stopped, which is also what a worker that dies mid-answer leaves.
 */
class SendProjectChatMessage
{
    public function __construct(
        private readonly AiBudget $budget,
        private readonly ProjectChatPrompt $prompt,
        private readonly AiStreamSlots $slots,
        private readonly CurrentOrganization $currentOrganization,
    ) {}

    /**
     * @throws AiBudgetExceeded before anything is saved, so it's still a 402
     * @throws ThrottleRequestsException 429 when the organization has too many streams running
     */
    public function handle(Project $project, User $user, string $message): AiEventStream
    {
        $organization = $this->currentOrganization->get();
        $this->budget->ensureAvailable($organization);
        $slot = $this->slots->acquire($organization);

        try {
            $this->save($project, $user, ChatMessageRole::User, $message, ChatMessageStatus::Complete);

            $history = ProjectChatMessage::query()->conversation($project, $user)
                ->latest('id')->limit(ProjectChatPrompt::HISTORY)->get()->reverse()->values();
            $answer = $this->save($project, $user, ChatMessageRole::Assistant, '', ChatMessageStatus::Stopped);
            $request = $this->prompt->request($project, $project->approvedSummary()->first(), $history, $user->id);
        } catch (Throwable $e) {
            $slot->release();

            throw $e;
        }

        // An update, not a save: if the chat was cleared meanwhile, nothing comes back.
        return new AiEventStream($organization, $slot, $request, onEnd: function (string $text, string $outcome, ?string $model) use ($answer) {
            ProjectChatMessage::query()->whereKey($answer->id)->update([
                'content' => $text,
                'status' => match ($outcome) {
                    'done' => ChatMessageStatus::Complete,
                    'stopped' => ChatMessageStatus::Stopped,
                    default => ChatMessageStatus::Failed,
                },
                'model' => $model,
            ]);
        });
    }

    private function save(Project $project, User $user, ChatMessageRole $role, string $content, ChatMessageStatus $status): ProjectChatMessage
    {
        $chatMessage = new ProjectChatMessage;
        $chatMessage->forceFill([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'role' => $role,
            'content' => $content,
            'status' => $status,
        ]);
        // organization_id comes from CurrentOrganization (BelongsToOrganization).
        $chatMessage->save();

        return $chatMessage;
    }
}
