<?php

namespace App\Actions\Account;

use App\Actions\Organizations\RemoveMember;
use App\Billing\Plans;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class DeleteAccount
{
    public function __construct(
        private readonly RemoveMember $removeMember,
        private readonly Plans $plans,
    ) {}

    /**
     * Delete the user (the request checked their password). Refused while they're the
     * last owner of an organization that has other members or a subscription Stripe
     * still bills. Organizations where they're the only member are deleted with their
     * data; they leave the others (audited there), then the user and tokens are deleted.
     *
     * @throws ValidationException the organizations that block it
     */
    public function handle(User $user): void
    {
        DB::transaction(function () use ($user) {
            $toDelete = collect();
            $toLeave = collect();
            $withMembers = collect();
            $subscribed = collect();

            foreach ($user->organizations()->orderBy('organizations.id')->get() as $organization) {
                $memberships = $this->lockedMemberships($organization);
                $own = $memberships->firstWhere('user_id', $user->id);

                $anotherOwner = $memberships->contains(
                    fn (Membership $membership) => $membership->user_id !== $user->id && $membership->role->is_owner,
                );

                match (true) {
                    ! $own->role->is_owner || $anotherOwner => $toLeave->push($organization),
                    $memberships->count() > 1 => $withMembers->push($organization),
                    $this->plans->paidSubscriptions($organization)->isNotEmpty() => $subscribed->push($organization),
                    default => $toDelete->push($organization),
                };
            }

            if ($withMembers->isNotEmpty() || $subscribed->isNotEmpty()) {
                throw ValidationException::withMessages(['account' => self::blockedMessage($withMembers, $subscribed)]);
            }

            foreach ($toLeave as $organization) {
                $this->removeMember->handle($organization, $organization->members()->findOrFail($user->id));
            }

            // Their projects, roles, audit log, AI usage and so on go with them (cascading foreign keys).
            $toDelete->each(fn (Organization $organization) => $organization->delete());

            $user->tokens()->delete();
            Password::broker()->deleteToken($user);
            $user->delete();
        });
    }

    /**
     * Why deletion is refused, naming only the reasons that apply.
     *
     * @param  Collection<int, Organization>  $withMembers  last owner, with other members
     * @param  Collection<int, Organization>  $subscribed  only member, but Stripe still bills it
     */
    private static function blockedMessage(Collection $withMembers, Collection $subscribed): string
    {
        $names = fn (Collection $organizations) => $organizations->pluck('name')->join(', ', ' and ');
        $reasons = [];

        if ($withMembers->isNotEmpty()) {
            $reasons[] = $withMembers->count() === 1
                ? "You're the last owner of {$names($withMembers)}, which has other members. Make one of them Owner first."
                : "You're the last owner of {$names($withMembers)}, which have other members. Make one of them Owner in each first.";
        }

        if ($subscribed->isNotEmpty()) {
            $reasons[] = $subscribed->count() === 1
                ? "{$names($subscribed)} still has a paid subscription. Cancel it in Billing first."
                : "{$names($subscribed)} still have paid subscriptions. Cancel them in Billing first.";
        }

        return implode(' ', $reasons);
    }

    /**
     * Every membership of the organization with its role, locked so no one can join,
     * leave or change roles while this decides. Locks the organization row first (as
     * AcceptInvitation does), then the owner rows and the rest in id order (as
     * EnsuresAnOwnerRemains does), so concurrent member changes wait instead of deadlocking.
     *
     * @return Collection<int, Membership>
     */
    private function lockedMemberships(Organization $organization): Collection
    {
        Organization::query()->whereKey($organization->id)->lockForUpdate()->first();

        $memberships = Membership::query()->where('organization_id', $organization->id)->orderBy('id');

        (clone $memberships)->whereHas('role', fn ($query) => $query->where('is_owner', true))->lockForUpdate()->get();

        $memberships = $memberships->lockForUpdate()->get();
        $memberships->load('role');

        return $memberships;
    }
}
