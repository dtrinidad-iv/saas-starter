<?php

namespace App\Models;

use App\Enums\OrganizationRole;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $email
 * @property OrganizationRole $role
 * @property int|null $invited_by
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 */
#[Fillable(['email', 'role', 'token_hash', 'invited_by', 'expires_at'])]
#[Hidden(['token_hash'])]
class OrganizationInvitation extends Model
{
    use BelongsToOrganization;

    public const LIFETIME_DAYS = 7;

    protected function casts(): array
    {
        return [
            'role' => OrganizationRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Look an invitation up by the token from its email link, in any organization.
     *
     * This is the one place that bypasses the organization scope: the person
     * joining is (by definition) not acting in the inviting organization yet.
     * Possession of the token is the proof; callers still check the email.
     */
    public static function findByToken(string $token, bool $lock = false): ?self
    {
        return static::withoutGlobalScope(OrganizationScope::class)
            ->where('token_hash', static::hashToken($token))
            ->when($lock, fn (Builder $query) => $query->lockForUpdate())
            ->first();
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('accepted_at');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return $this->accepted_at === null && ! $this->isExpired();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
