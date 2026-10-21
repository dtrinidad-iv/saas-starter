<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * An API token (a signed-in device), or a platform admin's read-only "view as user"
 * session when impersonator_id is set (StartImpersonation).
 *
 * @property int|null $impersonator_id
 * @property int|null $organization_id The organization a "view as user" session is looking at.
 * @property list<int>|null $viewed_organization_ids Organizations whose audit log has this session's entry.
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'viewed_organization_ids' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function impersonator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonator_id');
    }

    public function isImpersonation(): bool
    {
        return $this->impersonator_id !== null;
    }
}
