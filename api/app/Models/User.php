<?php

namespace App\Models;

use App\Notifications\ResetPassword;
use App\Notifications\VerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int|null $current_organization_id Only change via SwitchOrganization / CreateOrganization.
 * @property string|null $two_factor_secret Base32 TOTP secret (encrypted at rest).
 * @property list<string>|null $two_factor_recovery_codes sha256 hashes of the unused recovery codes.
 * @property bool $is_platform_admin Not fillable: only `admin:grant` / `admin:revoke` change it.
 * @property Carbon|null $suspended_at Not fillable: only SuspendUser / UnsuspendUser change it.
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_last_used_step'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** What login, the two-factor challenge and password reset say to a suspended user. */
    public const SUSPENDED_MESSAGE = 'This account is suspended. Contact support.';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'array',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_last_used_step' => 'integer',
            'is_platform_admin' => 'boolean',
            'suspended_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsToMany<Organization, $this, Membership, 'membership'>
     */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class)
            ->using(Membership::class)
            ->as('membership')
            ->withPivot('role_id')
            ->withTimestamps();
    }

    /**
     * The stored active organization. Not proof of access on its own: use
     * App\Support\CurrentOrganization, which also checks membership.
     *
     * @return BelongsTo<Organization, $this>
     */
    public function currentOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'current_organization_id');
    }

    /**
     * The current organization, but only if the user is still a member of it.
     * The returned model has the membership (role) loaded. In a platform admin's
     * "view as user" session it's the organization that session is looking at, so
     * switching there never changes the user's own.
     */
    public function activeOrganization(): ?Organization
    {
        $token = $this->currentAccessToken();
        $organizationId = $token instanceof PersonalAccessToken && $token->isImpersonation()
            ? $token->organization_id
            : $this->current_organization_id;

        if ($organizationId === null) {
            return null;
        }

        return $this->organizations()->whereKey($organizationId)->first();
    }

    public function isMemberOf(Organization|int $organization): bool
    {
        $id = $organization instanceof Organization ? $organization->getKey() : $organization;

        return $this->organizations()->whereKey($id)->exists();
    }

    /**
     * On only once a code from the app confirmed the setup.
     */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret !== null;
    }

    /**
     * Someone who runs the app. Admin routes also need two-factor (EnsurePlatformAdmin).
     */
    public function isPlatformAdmin(): bool
    {
        return $this->is_platform_admin === true;
    }

    /**
     * Suspended by a platform admin: can't log in, finish a challenge, reset the
     * password or use a token (AppServiceProvider) until unsuspended.
     */
    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    /**
     * Queued, and linking to the Nuxt page instead of a Laravel route.
     */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmail);
    }

    /**
     * Queued, and linking to the Nuxt page instead of a Laravel route.
     *
     * @param  string  $token
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPassword($token));
    }
}
