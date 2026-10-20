<?php

namespace App\Http\Controllers\Api;

use App\Actions\Organizations\ChangeMemberRole;
use App\Actions\Organizations\RemoveMember;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organizations\UpdateMemberRequest;
use App\Http\Resources\MemberResource;
use App\Models\Membership;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Members of the current organization. A {member} outside it is a 404.
 * CurrentOrganization is injected per action, not in the constructor: the router
 * reuses controller instances in long-lived processes (Octane, tests).
 */
class MemberController extends Controller
{
    public function index(CurrentOrganization $currentOrganization): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Membership::class);

        $members = $currentOrganization->get()->members()->orderBy('name')->get();
        Membership::loadRoles($members);

        return MemberResource::collection($members);
    }

    public function update(UpdateMemberRequest $request, int $member, ChangeMemberRole $action, CurrentOrganization $currentOrganization): MemberResource
    {
        $target = $this->findMember($currentOrganization, $member);
        $role = $request->role();

        Gate::authorize('update', [$target->membership, $role]);

        return new MemberResource($action->handle($currentOrganization->get(), $target, $role));
    }

    public function destroy(int $member, RemoveMember $action, CurrentOrganization $currentOrganization): Response
    {
        $target = $this->findMember($currentOrganization, $member);

        Gate::authorize('delete', $target->membership);

        $action->handle($currentOrganization->get(), $target);

        return response()->noContent();
    }

    private function findMember(CurrentOrganization $currentOrganization, int $id): User
    {
        return $currentOrganization->get()->members()->findOrFail($id);
    }
}
