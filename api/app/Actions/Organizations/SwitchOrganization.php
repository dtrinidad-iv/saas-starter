<?php

namespace App\Actions\Organizations;

use App\Actions\Admin\RecordImpersonatedView;
use App\Models\Organization;
use App\Models\User;
use App\Support\CurrentOrganization;
use App\Support\Impersonation;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class SwitchOrganization
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly Impersonation $impersonation,
        private readonly RecordImpersonatedView $recordImpersonatedView,
    ) {}

    /**
     * Make one of the user's organizations their current one. In a platform admin's
     * "view as user" session only that session moves (the token's organization), and
     * the organization's audit log says a platform admin viewed it.
     *
     * @throws ModelNotFoundException when the user is not a member (also when it
     *                                doesn't exist), so callers can't tell the two apart.
     */
    public function handle(User $user, int $organizationId): Organization
    {
        $organization = $user->organizations()->findOrFail($organizationId);

        if ($token = $this->impersonation->token()) {
            $this->recordImpersonatedView->handle($token, $organization, $user);
        } else {
            $user->forceFill(['current_organization_id' => $organization->id])->save();
        }

        $this->currentOrganization->forget();

        return $organization;
    }
}
