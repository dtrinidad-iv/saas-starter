<?php

namespace App\Actions\Organizations\Concerns;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

trait EnsuresAnOwnerRemains
{
    /**
     * Lock the organization row. Every transaction that changes memberships takes it
     * first (as DeleteAccount, AcceptInvitation and MakeMemberOwner do): the audit
     * entry's foreign key takes a share lock on this row, so locking it later could
     * deadlock with one of those.
     */
    protected function lockOrganization(Organization $organization): void
    {
        Organization::query()->whereKey($organization->id)->lockForUpdate()->first();
    }

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

    /**
     * Lock every owner membership row in id order, and return their user ids.
     *
     * @return Collection<int, int>
     */
    protected function lockOwnerRows(Organization $organization): Collection
    {
        return Membership::query()
            ->where('organization_id', $organization->id)
            ->whereHas('role', fn ($query) => $query->where('is_owner', true))
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('user_id');
    }

    protected function ensureAnotherOwner(Organization $organization, User $member, string $errorKey): void
    {
        // Lock every owner row, the member's own included, in a fixed order: two owners
        // acting on each other then wait for one another instead of deadlocking.
        $owners = $this->lockOwnerRows($organization);

        if ($owners->reject(fn (int $userId) => $userId === $member->id)->isEmpty()) {
            throw ValidationException::withMessages([
                $errorKey => 'An organization needs at least one owner. Make someone else an owner first.',
            ]);
        }
    }
}
