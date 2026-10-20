<?php

namespace App\Models;

use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A row in organization_user: one user's membership (and role) in one organization.
 *
 * @property int $organization_id
 * @property int $user_id
 * @property int $role_id
 * @property-read Role $role
 */
class Membership extends Pivot
{
    protected $table = 'organization_user';

    public $incrementing = true;

    /**
     * Not limited to the current organization: a user's memberships in their other
     * organizations need their roles too (e.g. the organization list). The
     * (organization_id, role_id) foreign key guarantees the role is this membership's own.
     *
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class)->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * Load the role of each model's membership pivot in one query. (Pivot relations
     * can't be eager loaded with ->with().)
     *
     * @param  iterable<Model>  $models  loaded through a relation using ->as('membership')
     */
    public static function loadRoles(iterable $models): void
    {
        $models = collect($models);

        $roles = Role::withoutGlobalScope(OrganizationScope::class)
            ->whereIn('id', $models->map(fn (Model $model) => $model->membership->role_id)->unique())
            ->get()
            ->keyBy('id');

        $models->each(fn (Model $model) => $model->membership->setRelation('role', $roles[$model->membership->role_id]));
    }
}
