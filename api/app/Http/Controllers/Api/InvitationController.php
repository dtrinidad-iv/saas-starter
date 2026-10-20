<?php

namespace App\Http\Controllers\Api;

use App\Actions\Organizations\InviteMember;
use App\Actions\Organizations\RevokeInvitation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organizations\StoreInvitationRequest;
use App\Http\Resources\InvitationResource;
use App\Models\OrganizationInvitation;
use App\Support\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Pending invitations of the current organization. Route model binding goes through
 * the organization scope, so another organization's invitation is a 404.
 */
class InvitationController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', OrganizationInvitation::class);

        return InvitationResource::collection(
            OrganizationInvitation::pending()->with(['inviter', 'role'])->latest()->get(),
        );
    }

    public function store(StoreInvitationRequest $request, CurrentOrganization $currentOrganization, InviteMember $action): JsonResponse
    {
        $role = $request->role();

        Gate::authorize('create', [OrganizationInvitation::class, $role]);

        $invitation = $action->handle($request->user(), $currentOrganization->get(), $request->validated('email'), $role);

        return (new InvitationResource($invitation))->response()->setStatusCode(201);
    }

    public function destroy(OrganizationInvitation $invitation, RevokeInvitation $action): Response
    {
        Gate::authorize('delete', $invitation);

        $action->handle($invitation);

        return response()->noContent();
    }
}
