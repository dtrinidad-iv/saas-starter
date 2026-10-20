<?php

namespace App\Http\Controllers\Api;

use App\Actions\Organizations\ChangeMemberRole;
use App\Actions\Organizations\RemoveMember;
use App\Enums\OrganizationRole;
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
 */
class MemberController extends Controller
{
    public function __construct(private readonly CurrentOrganization $currentOrganization) {}

    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Membership::class);

        return MemberResource::collection(
            $this->currentOrganization->get()->members()->orderBy('name')->get(),
        );
    }

    public function update(UpdateMemberRequest $request, int $member, ChangeMemberRole $action): MemberResource
    {
        $target = $this->findMember($member);
        $role = $request->enum('role', OrganizationRole::class);

        Gate::authorize('update', [$target->membership, $role]);

        return new MemberResource($action->handle($this->currentOrganization->get(), $target, $role));
    }

    public function destroy(int $member, RemoveMember $action): Response
    {
        $target = $this->findMember($member);

        Gate::authorize('delete', $target->membership);

        $action->handle($this->currentOrganization->get(), $target);

        return response()->noContent();
    }

    private function findMember(int $id): User
    {
        return $this->currentOrganization->get()->members()->findOrFail($id);
    }
}
