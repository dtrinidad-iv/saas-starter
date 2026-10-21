<?php

namespace App\Http\Controllers\Api;

use App\Actions\ProjectChat\ClearProjectChat;
use App\Actions\ProjectChat\SendProjectChatMessage;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProjectChat\SendProjectChatMessageRequest;
use App\Http\Resources\ProjectChatMessageResource;
use App\Http\Responses\AiEventStream;
use App\Models\Project;
use App\Models\ProjectChatMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The current user's own AI chat about a project. {project} resolves through the
 * organization scope, so another organization's project is a 404; other members'
 * chats are never read here.
 */
class ProjectChatController extends Controller
{
    private const SHOWN = 50;

    // The last messages, oldest first.
    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('chat', $project);

        $messages = ProjectChatMessage::query()->conversation($project, $request->user())
            ->latest('id')->limit(self::SHOWN)->get()->reverse()->values();

        return ProjectChatMessageResource::collection($messages);
    }

    // Streamed (text/event-stream); see AiEventStream.
    public function store(SendProjectChatMessageRequest $request, Project $project, SendProjectChatMessage $action): AiEventStream
    {
        Gate::authorize('chat', $project);

        return $action->handle($project, $request->user(), $request->validated('message'));
    }

    public function destroy(Request $request, Project $project, ClearProjectChat $action): Response
    {
        Gate::authorize('chat', $project);

        $action->handle($project, $request->user());

        return response()->noContent();
    }
}
