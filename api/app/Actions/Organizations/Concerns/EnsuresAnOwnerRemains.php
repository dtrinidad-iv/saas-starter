<?php

namespace App\Actions\Organizations\Concerns;

use App\Enums\OrganizationRole;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Validation\ValidationException;

trait EnsuresAnOwnerRemains
{
    /**
     * Fail if $member is the organization's only owner. Call inside a transaction:
     * the owner rows are locked so two owners can't demote each other at once.
     */
    protected function ensureAnotherOwner(Organization $organization, User $member, string $errorKey): void
    {
        $otherOwners = Membership::query()
            ->where('organization_id', $organization->id)
            ->where('role', OrganizationRole::Owner)
            ->where('user_id', '!=', $member->id)
            ->lockForUpdate()
            ->get()
            ->count();

        if ($otherOwners === 0) {
            throw ValidationException::withMessages([
                $errorKey => 'An organization needs at least one owner. Make someone else an owner first.',
            ]);
        }
    }
}
