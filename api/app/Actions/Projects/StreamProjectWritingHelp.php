<?php

namespace App\Actions\Projects;

use App\Ai\AiBudget;
use App\Ai\AiStreamSlots;
use App\Ai\Exceptions\AiBudgetExceeded;
use App\Ai\Prompts\ProjectWritingPrompt;
use App\Enums\WritingHelpMode;
use App\Http\Responses\AiEventStream;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Http\Exceptions\ThrottleRequestsException;

/**
 * A rewritten project description, streamed back to the form. Nothing is saved:
 * the user accepts it into the form and saves the project as usual.
 */
class StreamProjectWritingHelp
{
    public function __construct(
        private readonly AiBudget $budget,
        private readonly ProjectWritingPrompt $prompt,
        private readonly AiStreamSlots $slots,
        private readonly CurrentOrganization $currentOrganization,
    ) {}

    /**
     * @param  array{mode: string, name?: string|null, description?: string|null}  $input
     *
     * Returns the response, not just the request: the stream must run as this
     * organization and release its slot when it ends.
     *
     * @throws AiBudgetExceeded before streaming starts, so it's still a 402
     * @throws ThrottleRequestsException 429 when the organization has too many streams running
     */
    public function handle(array $input, User $user): AiEventStream
    {
        $organization = $this->currentOrganization->get();
        $this->budget->ensureAvailable($organization);

        return new AiEventStream(
            $organization,
            $this->slots->acquire($organization),
            $this->prompt->request(
                WritingHelpMode::from($input['mode']),
                $input['name'] ?? null,
                $input['description'] ?? null,
                $user->id,
            ),
            ProjectWritingPrompt::MAX_LENGTH,
        );
    }
}
