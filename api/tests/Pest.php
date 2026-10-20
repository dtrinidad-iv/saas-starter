<?php

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * A user who is a member of a new organization (with the given role) and has it as current.
 *
 * @return array{0: User, 1: Organization}
 */
function userInOrganization(OrganizationRole $role = OrganizationRole::Owner, string $name = 'Acme'): array
{
    $user = User::factory()->create();
    $organization = Organization::factory()->withMember($user, $role)->create(['name' => $name]);
    $user->forceFill(['current_organization_id' => $organization->id])->save();

    return [$user, $organization];
}

/**
 * Add a new user to the organization with the given role (their current organization).
 */
function addMember(Organization $organization, OrganizationRole $role = OrganizationRole::Member, array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $organization->members()->attach($user, ['role' => $role]);
    $user->forceFill(['current_organization_id' => $organization->id])->save();

    return $user;
}

/**
 * Create an invitation directly (no email). Returns the invitation and its plain token.
 *
 * @return array{0: OrganizationInvitation, 1: string}
 */
function createInvitation(Organization $organization, string $email, OrganizationRole $role = OrganizationRole::Member, array $attributes = []): array
{
    $token = Str::random(48);

    app(CurrentOrganization::class)->set($organization);
    // forceFill: tests may set non-fillable columns such as accepted_at.
    $invitation = (new OrganizationInvitation)->forceFill(array_merge([
        'email' => $email,
        'role' => $role,
        'token_hash' => OrganizationInvitation::hashToken($token),
        'expires_at' => now()->addDays(7),
    ], $attributes));
    $invitation->save();
    app(CurrentOrganization::class)->forget();

    return [$invitation, $token];
}
