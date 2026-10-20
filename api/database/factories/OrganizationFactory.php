<?php

namespace Database\Factories;

use App\Actions\Roles\CreateDefaultRoles;
use App\Enums\DefaultRole;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
        ];
    }

    /**
     * Every organization starts with the default roles, like CreateOrganization.
     */
    public function configure(): static
    {
        return $this->afterCreating(fn (Organization $organization) => app(CreateDefaultRoles::class)->handle($organization));
    }

    /**
     * Add the user as a member with the given default role.
     */
    public function withMember(User $user, DefaultRole $role = DefaultRole::Member): static
    {
        return $this->afterCreating(fn (Organization $organization) => $organization->members()->attach($user, [
            'role_id' => Role::withoutGlobalScope(OrganizationScope::class)
                ->where('organization_id', $organization->id)
                ->where('name', $role->value)
                ->value('id'),
        ]));
    }
}
