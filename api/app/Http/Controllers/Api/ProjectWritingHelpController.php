<?php

namespace App\Http\Controllers\Api;

use App\Actions\Projects\StreamProjectWritingHelp;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\ProjectWritingHelpRequest;
use App\Http\Responses\AiEventStream;
use App\Models\Project;
use Illuminate\Support\Facades\Gate;

/**
 * AI writing help for the project form, streamed as server-sent events
 * (AiEventStream). {project} resolves through the organization scope, so another
 * organization's project is a 404.
 */
class ProjectWritingHelpController extends Controller
{
    // For the create form.
    public function store(ProjectWritingHelpRequest $request, StreamProjectWritingHelp $action): AiEventStream
    {
        Gate::authorize('writeWithAi', Project::class);

        return $action->handle($request->validated(), $request->user());
    }

    // For the edit form of an existing project.
    public function update(ProjectWritingHelpRequest $request, Project $project, StreamProjectWritingHelp $action): AiEventStream
    {
        Gate::authorize('writeWithAi', $project);

        return $action->handle($request->validated(), $request->user());
    }
}
