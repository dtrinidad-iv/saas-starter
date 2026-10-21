<?php

namespace App\Actions\Admin;

use App\Actions\Audit\RecordAudit;
use App\Enums\AuditAction;
use App\Models\Organization;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RecordImpersonatedView
{
    public function __construct(private readonly RecordAudit $recordAudit) {}

    /**
     * Point a "view as user" session at one of the user's organizations, and tell
     * the organization: a "Platform admin" entry in its audit log, once per session
     * and organization. Both in one transaction, so the session never moves without
     * the entry. Locks the organization row first, then the token (the project's lock
     * order). The caller has checked the user is a member.
     */
    public function handle(PersonalAccessToken $token, Organization $organization, User $user): void
    {
        DB::transaction(function () use ($token, $organization, $user) {
            Organization::query()->whereKey($organization->id)->sharedLock()->first();
            /** @var PersonalAccessToken $locked */
            $locked = PersonalAccessToken::query()->lockForUpdate()->findOrFail($token->getKey());
            $viewed = $locked->viewed_organization_ids ?? [];
            $firstView = ! in_array($organization->id, $viewed, true);

            $locked->forceFill([
                'organization_id' => $organization->id,
                'viewed_organization_ids' => $firstView ? [...$viewed, $organization->id] : $viewed,
            ])->save();

            if ($firstView) {
                $this->recordAudit->handle(
                    AuditAction::SupportViewedAs,
                    ['type' => 'member', 'id' => $user->id, 'label' => $user->name],
                    organization: $organization,
                    byPlatformAdmin: true,
                );
            }
        });

        // This request's token too: User::activeOrganization reads it.
        $token->forceFill(['organization_id' => $organization->id])->syncOriginalAttribute('organization_id');
    }
}
