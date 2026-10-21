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
     * and how many devices are signed in. Recorded in the platform log, unless it's
     * the page refreshed after an action ($record false: the action has its own entry).
     */
    public function handle(User $user, bool $record = true): User
    {
        $user->load(['organizations' => fn ($query) => $query->orderBy('organizations.name')]);
        Membership::loadRoles($user->organizations);

        // Devices, so not support's own "view as user" sessions.
        $devices = ['tokens' => fn ($query) => $query->whereNull('impersonator_id')];
        $user->loadCount($devices);
        $user->loadMax($devices, 'last_used_at');

        if ($record) {
            $this->recordAudit->handle(PlatformAuditAction::UserViewed, [
                'type' => 'user',
                'id' => $user->id,
                'label' => $user->email,
            ]);
        }

        return $user;
    }
}
