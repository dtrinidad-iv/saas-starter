<?php

namespace App\Actions\Roles;

use App\Models\OrganizationInvitation;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteRole
{
    /**
     * Delete a role nobody holds. Pending invitations for it block the delete too;
     * expired or accepted ones just lose their role (foreign key ON DELETE SET NULL).
     */
    public function handle(Role $role): void
    {
        DB::transaction(function () use ($role) {
            // Lock the role so nobody is given it while we check.
            Role::query()->whereKey($role->id)->lockForUpdate()->first();

            $members = $role->memberships()->count();
            $invitations = OrganizationInvitation::pending()
                ->where('role_id', $role->id)
                ->where('expires_at', '>', now())
                ->count();

            if ($members > 0 || $invitations > 0) {
                throw ValidationException::withMessages([
                    'role' => sprintf(
                        'This role is used by %s and %s. Give them another role first.',
                        trans_choice('{1} 1 member|[0,*] :count members', $members),
                        trans_choice('{1} 1 pending invitation|[0,*] :count pending invitations', $invitations),
                    ),
                ]);
            }

            $role->delete();
        });
    }
}
