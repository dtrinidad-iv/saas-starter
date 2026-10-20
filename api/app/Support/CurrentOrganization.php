<?php

namespace App\Support;

use App\Enums\Permission;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use LogicException;

/**
 * The organization the current request (or job) acts in.
 *
 * Bound as a scoped singleton: resolved at most once per request/job.
 * For an authenticated user it is their stored current organization, but
 * only while they are still a member of it. Never taken from client input.
 */
class CurrentOrganization
{
    private bool $resolved = false;

    private ?Organization $organization = null;

    private ?Role $role = null;

    public function __construct(private readonly AuthFactory $auth) {}

    public function get(): ?Organization
    {
        $this->resolve();

        return $this->organization;
    }

    public function id(): ?int
    {
        return $this->get()?->getKey();
    }

    /**
     * The acting user's role here; null without a user (e.g. in a job) or organization.
     */
    public function role(): ?Role
    {
        $this->resolve();

        return $this->role;
    }

    public function can(Permission $permission): bool
    {
        return $this->role()?->has($permission) ?? false;
    }

    /**
     * Act in an organization explicitly, e.g. inside a queued job that has no
     * logged-in user. The caller is responsible for having checked access.
     */
    public function set(Organization $organization, ?Role $role = null): void
    {
        if ($role !== null && $role->organization_id !== $organization->id) {
            throw new LogicException('The role belongs to another organization.');
        }

        $this->organization = $organization;
        $this->role = $role;
        $this->resolved = true;
    }

    /**
     * Run code acting in an organization, then put back whatever was current before
     * (so a job run synchronously inside a request or another job doesn't change
     * the caller's organization). The caller is responsible for having checked access.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runAs(Organization $organization, Closure $callback): mixed
    {
        [$resolved, $previous, $role] = [$this->resolved, $this->organization, $this->role];
        $this->set($organization);

        try {
            return $callback();
        } finally {
            [$this->resolved, $this->organization, $this->role] = [$resolved, $previous, $role];
        }
    }

    /**
     * Drop the cached value, e.g. after the user switched organizations.
     */
    public function forget(): void
    {
        $this->organization = null;
        $this->role = null;
        $this->resolved = false;
    }

    private function resolve(): void
    {
        if ($this->resolved) {
            return;
        }

        $user = $this->auth->guard()->user();

        // Not authenticated (yet): don't cache, so a later call after auth still resolves.
        if (! $user instanceof User) {
            return;
        }

        $this->resolved = true;
        $this->organization = $user->activeOrganization();
        $this->role = $this->organization?->membership?->role;
    }
}
