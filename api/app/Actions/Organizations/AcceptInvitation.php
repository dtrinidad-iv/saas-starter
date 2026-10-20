<?php

namespace App\Actions\Organizations;

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AcceptInvitation
{
    public function __construct(private readonly SwitchOrganization $switchOrganization) {}

    /**
     * Join the invitation's organization with its role and switch to it.
     *
     * @throws NotFoundHttpException unknown, revoked, expired or already used
     * @throws AuthorizationException the invitation is for another email address
     */
    public function handle(User $user, string $token): Organization
    {
        return DB::transaction(function () use ($user, $token) {
            $invitation = OrganizationInvitation::findByToken($token, lock: true);

            static::ensureUsableBy($invitation, $user);

            $organization = Organization::findOrFail($invitation->organization_id);

            // Already a member (e.g. added another way): keep the existing role.
            if (! $user->isMemberOf($organization)) {
                $organization->members()->attach($user, ['role_id' => $invitation->role_id]);
            }

            $invitation->forceFill(['accepted_at' => now()])->save();

            return $this->switchOrganization->handle($user, $organization->id);
        });
    }

    /**
     * @phpstan-assert OrganizationInvitation $invitation
     */
    public static function ensureUsableBy(?OrganizationInvitation $invitation, User $user): void
    {
        if ($invitation === null || ! $invitation->isUsable()) {
            throw new NotFoundHttpException('This invitation is no longer valid.');
        }

        if (Str::lower($user->email) !== $invitation->email) {
            throw new AuthorizationException('This invitation was sent to a different email address.');
        }
    }
}
