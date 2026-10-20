<?php

namespace App\Actions\Organizations\Concerns;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Validation\ValidationException;

trait EnsuresAnOwnerRemains
{
    /**
     * Fail if $member is the organization's only owner. Call inside a transaction:
     * the owner rows are locked so two owners can't demote each other at once.
     */
    /**
     * The member's role, with their membership row locked. Call inside a transaction,
     * after ensureAnotherOwner().
     */
    protected function lockedRole(Organization $organization, User $member): Role
    {
        return Membership::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $member->id)
            ->lockForUpdate()
            ->firstOrFail()
            ->role;
    }

    protected function ensureAnotherOwner(Organization $organization, User $member, string $errorKey): void
    {
        // Lock every owner row, the member's own included, in a fixed order: two owners
        // acting on each other then wait for one another instead of deadlocking.
        $owners = Membership::query()
            ->where('organization_id', $organization->id)
            ->whereHas('role', fn ($query) => $query->where('is_owner', true))
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('user_id');

        if ($owners->reject(fn (int $userId) => $userId === $member->id)->isEmpty()) {
            throw ValidationException::withMessages([
                $errorKey => 'An organization needs at least one owner. Make someone else an owner first.',
            ]);
        }
    }
}
