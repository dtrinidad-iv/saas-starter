<?php

namespace App\Models;

use App\Enums\OrganizationRole;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A row in organization_user: one user's membership (and role) in one organization.
 *
 * @property int $organization_id
 * @property int $user_id
 * @property OrganizationRole $role
 */
class Membership extends Pivot
{
    protected $table = 'organization_user';

    public $incrementing = true;

    protected function casts(): array
    {
        return [
            'role' => OrganizationRole::class,
        ];
    }
}
