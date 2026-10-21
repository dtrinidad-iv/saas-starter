<?php

use App\Enums\AuditAction;
use App\Enums\ChatMessageRole;
use App\Enums\DefaultRole;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectChatMessage;
use App\Models\Role;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;

it('needs the password', function () {
    [$user] = userInOrganization();

    $this->actingAs($user, 'sanctum')
        ->deleteJson('/api/account', ['password' => 'wrong'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['password']);

    expect($user->fresh())->not->toBeNull();
});

it('deletes the user, their tokens, and organizations where they are the only member', function () {
    [$user, $organization] = userInOrganization(name: 'Solo');
    $token = $user->createToken('api')->plainTextToken;
    $project = inOrganization($organization, fn () => Project::factory()->create());

    $this->withToken($token)->deleteJson('/api/account', ['password' => 'password'])->assertNoContent();

    expect(User::find($user->id))->toBeNull()
        ->and(Organization::find($organization->id))->toBeNull()
        ->and(Project::withoutGlobalScope(OrganizationScope::class)->find($project->id))->toBeNull()
        ->and(Role::withoutGlobalScope(OrganizationScope::class)->where('organization_id', $organization->id)->exists())->toBeFalse();

    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
});

it('leaves organizations that have another owner, audited there, and keeps their data', function () {
    $owner = User::factory()->create();
    $organization = Organization::factory()->withMember($owner, DefaultRole::Owner)->create(['name' => 'Shared']);
    $user = addMember($organization, DefaultRole::Owner);
    $project = inOrganization($organization, fn () => Project::factory()->create(['created_by' => $user->id]));
    createChatMessage($organization, $project, $user, ChatMessageRole::User, 'My question');

    $this->actingAs($user, 'sanctum')->deleteJson('/api/account', ['password' => 'password'])->assertNoContent();

    expect(User::find($user->id))->toBeNull()
        ->and(Organization::find($organization->id))->not->toBeNull()
        ->and($organization->members()->pluck('users.id')->all())->toBe([$owner->id])
        ->and(Project::withoutGlobalScope(OrganizationScope::class)->find($project->id)->created_by)->toBeNull()
        ->and(ProjectChatMessage::withoutGlobalScope(OrganizationScope::class)->where('user_id', $user->id)->exists())->toBeFalse();

    $entry = auditLogOf($organization)->last();
    expect($entry->action)->toBe(AuditAction::MemberRemoved)
        ->and($entry->subject_id)->toBe($user->id)
        ->and($entry->actor_id)->toBeNull();
});

it('leaves organizations where they are not an owner', function () {
    $owner = User::factory()->create();
    $organization = Organization::factory()->withMember($owner, DefaultRole::Owner)->create();
    $user = addMember($organization, DefaultRole::Admin);

    $this->actingAs($user, 'sanctum')->deleteJson('/api/account', ['password' => 'password'])->assertNoContent();

    expect(Organization::find($organization->id))->not->toBeNull()
        ->and($organization->members()->pluck('users.id')->all())->toBe([$owner->id]);
});

it('is refused while they are the last owner of an organization with other members', function () {
    [$user, $organization] = userInOrganization(name: 'Acme');
    addMember($organization, DefaultRole::Admin);

    $this->actingAs($user, 'sanctum')
        ->deleteJson('/api/account', ['password' => 'password'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['account'])
        ->assertJsonPath('errors.account.0', fn (string $message) => $message === "You're the last owner of Acme, which has other members. Make one of them Owner first.");

    expect(User::find($user->id))->not->toBeNull()
        ->and($organization->members()->count())->toBe(2);
});

it('is refused while an organization they alone are in still has a paid subscription', function () {
    [$user, $organization] = userInOrganization(name: 'Paying');
    subscribe($organization);

    $this->actingAs($user, 'sanctum')
        ->deleteJson('/api/account', ['password' => 'password'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.account.0', 'Paying still has a paid subscription. Cancel it in Billing first.');

    expect(Organization::find($organization->id))->not->toBeNull();
});

it('deletes a sole-member organization whose subscription has ended', function () {
    [$user, $organization] = userInOrganization();
    subscribe($organization, ['stripe_status' => 'canceled', 'ends_at' => now()->subDay()]);

    $this->actingAs($user, 'sanctum')->deleteJson('/api/account', ['password' => 'password'])->assertNoContent();

    expect(Organization::find($organization->id))->toBeNull();
});

it('lists every blocking organization and changes nothing anywhere', function () {
    [$user, $acme] = userInOrganization(name: 'Acme');
    addMember($acme);
    $beta = Organization::factory()->withMember($user, DefaultRole::Owner)->create(['name' => 'Beta']);
    addMember($beta);
    $solo = Organization::factory()->withMember($user, DefaultRole::Owner)->create(['name' => 'Solo']);

    $this->actingAs($user, 'sanctum')
        ->deleteJson('/api/account', ['password' => 'password'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.account.0', "You're the last owner of Acme and Beta, which have other members. Make one of them Owner in each first.");

    expect(Organization::find($solo->id))->not->toBeNull()
        ->and($user->organizations()->count())->toBe(3);
});

it("never touches another organization's data", function () {
    [$user, $mine] = userInOrganization(name: 'Mine');
    [$other, $theirs] = userInOrganization(name: 'Theirs');
    $theirProject = inOrganization($theirs, fn () => Project::factory()->create());
    $auditCount = AuditLog::withoutGlobalScope(OrganizationScope::class)->where('organization_id', $theirs->id)->count();

    $this->actingAs($user, 'sanctum')->deleteJson('/api/account', ['password' => 'password'])->assertNoContent();

    expect(Organization::find($mine->id))->toBeNull()
        ->and(Organization::find($theirs->id))->not->toBeNull()
        ->and($theirs->members()->pluck('users.id')->all())->toBe([$other->id])
        ->and(Project::withoutGlobalScope(OrganizationScope::class)->find($theirProject->id))->not->toBeNull()
        ->and(AuditLog::withoutGlobalScope(OrganizationScope::class)->where('organization_id', $theirs->id)->count())->toBe($auditCount);
});

it('removes their pending password reset tokens', function () {
    [$user] = userInOrganization();
    Password::broker()->createToken($user);

    $this->actingAs($user, 'sanctum')->deleteJson('/api/account', ['password' => 'password'])->assertNoContent();

    expect(DB::table('password_reset_tokens')->where('email', $user->email)->exists())->toBeFalse();
});

it('names both reasons when both apply', function () {
    [$user, $acme] = userInOrganization(name: 'Acme');
    addMember($acme);
    $paying = Organization::factory()->withMember($user, DefaultRole::Owner)->create(['name' => 'Paying']);
    subscribe($paying);

    $this->actingAs($user, 'sanctum')
        ->deleteJson('/api/account', ['password' => 'password'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.account.0', "You're the last owner of Acme, which has other members. Make one of them Owner first. Paying still has a paid subscription. Cancel it in Billing first.");
});
