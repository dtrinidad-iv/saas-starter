<?php

use App\Enums\DefaultRole;
use App\Models\Project;
use Illuminate\Support\Facades\Mail;

beforeEach(fn () => Mail::fake());

dataset('verified routes', [
    'invite a member' => ['post', '/api/invitations'],
    'accept an invitation' => ['post', '/api/join/some-token'],
    'subscribe' => ['post', '/api/billing/checkout'],
    'buy AI credits' => ['post', '/api/billing/credits/checkout'],
    'open the billing portal' => ['post', '/api/billing/portal'],
    'writing help (new project)' => ['post', '/api/projects/writing-help'],
    'writing help (project)' => ['post', '/api/projects/{project}/writing-help'],
    'request a summary' => ['post', '/api/projects/{project}/summary'],
    'ask the project chat' => ['post', '/api/projects/{project}/chat'],
]);

it('refuses an unverified user with email_unverified', function (string $method, string $uri) {
    [$user, $organization] = userInOrganization(DefaultRole::Owner);
    $user->forceFill(['email_verified_at' => null])->save();
    $project = inOrganization($organization, fn () => Project::factory()->create());

    $this->actingAs($user, 'sanctum')
        ->json($method, str_replace('{project}', (string) $project->id, $uri))
        ->assertForbidden()
        ->assertJsonPath('code', 'email_unverified');

    $this->ai->assertNothingSent();
    Mail::assertNothingQueued();
})->with('verified routes');

it('lets a verified user through the gate', function (string $method, string $uri) {
    [$user, $organization] = userInOrganization(DefaultRole::Owner);
    $project = inOrganization($organization, fn () => Project::factory()->create());

    $response = $this->actingAs($user, 'sanctum')
        ->json($method, str_replace('{project}', (string) $project->id, $uri));

    expect($response->json('code'))->not->toBe('email_unverified');
})->with('verified routes');

it('still lets an unverified user use the rest of their organization', function () {
    [$user, $organization] = userInOrganization(DefaultRole::Owner);
    $user->forceFill(['email_verified_at' => null])->save();
    inOrganization($organization, fn () => Project::factory()->create(['name' => 'Website']));

    $this->actingAs($user, 'sanctum')->getJson('/api/projects')->assertOk()->assertJsonPath('data.0.name', 'Website');
    $this->actingAs($user, 'sanctum')->postJson('/api/projects', ['name' => 'Second'])->assertCreated();
    $this->actingAs($user, 'sanctum')->getJson('/api/members')->assertOk();
    $this->actingAs($user, 'sanctum')->getJson('/api/billing')->assertOk();
});
