<?php

namespace App\Actions\Organizations;

use App\Actions\Roles\CreateDefaultRoles;
use App\Enums\DefaultRole;
use App\Models\Organization;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Support\Facades\DB;

class CreateOrganization
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly CreateDefaultRoles $createDefaultRoles,
    ) {}

    /**
     * Create an organization (with its default roles) owned by the user and make it their current one.
     */
    public function handle(User $owner, string $name): Organization
    {
        $organization = DB::transaction(function () use ($owner, $name) {
            $organization = Organization::create(['name' => $name]);
            $roles = $this->createDefaultRoles->handle($organization);
            $organization->members()->attach($owner, ['role_id' => $roles[DefaultRole::Owner->value]->id]);

            $owner->forceFill(['current_organization_id' => $organization->id])->save();

            return $organization;
        });

        $this->currentOrganization->forget();

        // Re-read through the user so the membership (role) is loaded.
        return $owner->organizations()->findOrFail($organization->id);
    }
}
