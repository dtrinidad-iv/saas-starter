<?php

namespace App\Actions\Admin;

use App\Enums\PlatformAuditAction;
use App\Models\Membership;
use App\Models\User;

class ShowUser
{
    public function __construct(private readonly RecordPlatformAudit $recordAudit) {}

    /**
     * One user for a platform admin: their organizations with the role in each,
     * and how many devices are signed in. Recorded in the platform log.
     */
    public function handle(User $user): User
    {
        $user->load(['organizations' => fn ($query) => $query->orderBy('organizations.name')]);
        Membership::loadRoles($user->organizations);

        $user->loadCount('tokens');
        $user->loadMax('tokens', 'last_used_at');

        $this->recordAudit->handle(PlatformAuditAction::UserViewed, [
            'type' => 'user',
            'id' => $user->id,
            'label' => $user->email,
        ]);

        return $user;
    }
}
