<?php

use App\Enums\DefaultRole;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->owner, $this->acme] = userInOrganization(DefaultRole::Owner, 'Acme');
    [$this->invitee, $this->inviteeOrganization] = userInOrganization(DefaultRole::Owner, 'Invitee Org');
    $this->invitee->forceFill(['email' => 'invitee@example.com'])->save();

    [$this->invitation, $this->token] = createInvitation(
        $this->acme, 'invitee@example.com', DefaultRole::Admin, ['invited_by' => $this->owner->id],
    );
});

it('shows the invitation to the invited person', function () {
    Sanctum::actingAs($this->invitee);

    $this->getJson("/api/join/{$this->token}")
        ->assertOk()
        ->assertJsonPath('data.organization.name', 'Acme')
        ->assertJsonPath('data.invited_by', $this->owner->name)
        ->assertJsonPath('data.role.name', 'Admin')
        ->assertJsonPath('data.email', 'invitee@example.com');
});

it('joins with the invited role and switches to the organization', function () {
    Sanctum::actingAs($this->invitee);

    $this->postJson("/api/join/{$this->token}")
        ->assertOk()
        ->assertJsonPath('data.id', $this->acme->id)
        ->assertJsonPath('data.role.name', 'Admin');

    expect($this->invitee->isMemberOf($this->acme))->toBeTrue()
        ->and($this->invitee->fresh()->current_organization_id)->toBe($this->acme->id)
        ->and($this->invitee->isMemberOf($this->inviteeOrganization))->toBeTrue()
        ->and($this->invitation->fresh()->accepted_at)->not->toBeNull();

    // Now acting in Acme.
    $this->getJson('/api/members')->assertOk()->assertJsonCount(2, 'data');
});

it('matches the email case-insensitively', function () {
    $this->invitee->forceFill(['email' => 'Invitee@Example.com'])->save();
    Sanctum::actingAs($this->invitee);

    $this->postJson("/api/join/{$this->token}")->assertOk();
});

it('refuses someone with a different email', function () {
    $stranger = User::factory()->create(['email' => 'stranger@example.com']);
    Sanctum::actingAs($stranger);

    $this->getJson("/api/join/{$this->token}")
        ->assertForbidden()
        ->assertJson(['message' => 'This invitation was sent to a different email address.']);
    $this->postJson("/api/join/{$this->token}")->assertForbidden();

    expect($stranger->isMemberOf($this->acme))->toBeFalse()
        ->and($this->invitation->fresh()->accepted_at)->toBeNull();
});

it('rejects an expired invitation', function () {
    $this->invitation->forceFill(['expires_at' => now()->subMinute()])->saveQuietly();
    Sanctum::actingAs($this->invitee);

    $this->getJson("/api/join/{$this->token}")->assertNotFound();
    $this->postJson("/api/join/{$this->token}")->assertNotFound();

    expect($this->invitee->isMemberOf($this->acme))->toBeFalse();
});

it('cannot be used twice', function () {
    Sanctum::actingAs($this->invitee);
    $this->postJson("/api/join/{$this->token}")->assertOk();

    // Leave, then try to come back with the same link.
    $this->deleteJson("/api/members/{$this->invitee->id}")->assertNoContent();

    $this->postJson("/api/join/{$this->token}")
        ->assertNotFound()
        ->assertJson(['message' => 'This invitation is no longer valid.']);
    expect($this->invitee->isMemberOf($this->acme))->toBeFalse();
});

it('rejects a revoked or unknown token', function () {
    OrganizationInvitation::withoutGlobalScopes()->whereKey($this->invitation->id)->delete();
    Sanctum::actingAs($this->invitee);

    $this->postJson("/api/join/{$this->token}")->assertNotFound();
    $this->postJson('/api/join/not-a-real-token')->assertNotFound();
});

it('keeps the existing role when the person is already a member', function () {
    $this->acme->members()->attach($this->invitee, ['role_id' => roleOf($this->acme, DefaultRole::Member)->id]);
    Sanctum::actingAs($this->invitee);

    $this->postJson("/api/join/{$this->token}")
        ->assertOk()
        ->assertJsonPath('data.role.name', 'Member');
});

it('requires authentication', function () {
    $this->getJson("/api/join/{$this->token}")->assertUnauthorized();
    $this->postJson("/api/join/{$this->token}")->assertUnauthorized();
});
