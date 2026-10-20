<?php

namespace App\Http\Controllers\Api;

use App\Actions\Projects\CreateProject;
use App\Actions\Projects\DeleteProject;
use App\Actions\Projects\UpdateProject;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\StoreProjectRequest;
use App\Http\Requests\Projects\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Projects of the current organization. {project} is bound through the
 * organization scope, so another organization's project is a 404.
 */
class ProjectController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Project::class);

        $search = trim((string) $request->query('search', ''));

        $projects = Project::query()
            ->with('creator')
            ->when($search !== '', fn ($query) => $query->whereLike('name', '%'.addcslashes($search, '%_\\').'%'))
            ->latest()
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return ProjectResource::collection($projects);
    }

    public function store(StoreProjectRequest $request, CreateProject $action): JsonResponse
    {
        Gate::authorize('create', Project::class);

        $project = $action->handle($request->user(), $request->validated());

        return (new ProjectResource($project))->response()->setStatusCode(201);
    }

    public function show(Project $project): ProjectResource
    {
        Gate::authorize('view', $project);

        return new ProjectResource($project->load('creator'));
    }

    public function update(UpdateProjectRequest $request, Project $project, UpdateProject $action): ProjectResource
    {
        Gate::authorize('update', $project);

        return new ProjectResource($action->handle($project, $request->validated()));
    }

    public function destroy(Project $project, DeleteProject $action): Response
    {
        Gate::authorize('delete', $project);

        $action->handle($project);

        return response()->noContent();
    }
}
