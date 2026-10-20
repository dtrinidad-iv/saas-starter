<?php

use App\Enums\OrganizationRole;
use App\Models\OrganizationInvitation;
use Laravel\Sanctum\Sanctum;

it('lists pending invitations of the current organization only', function () {
    [$owner, $acme] = userInOrganization(OrganizationRole::Owner, 'Acme');
    [, $otherOrganization] = userInOrganization(OrganizationRole::Owner, 'Other Corp');

    [$pending] = createInvitation($acme, 'pending@example.com');
    createInvitation($acme, 'accepted@example.com', attributes: ['accepted_at' => now()]);
    [$expired] = createInvitation($acme, 'expired@example.com', attributes: ['expires_at' => now()->subDay()]);
    [$foreign] = createInvitation($otherOrganization, 'foreign@example.com');

    Sanctum::actingAs($owner);

    $response = $this->getJson('/api/invitations')->assertOk();

    expect(collect($response->json('data'))->pluck('id')->sort()->values()->all())
        ->toBe(collect([$pending->id, $expired->id])->sort()->values()->all());
    expect(collect($response->json('data'))->firstWhere('id', $expired->id)['is_expired'])->toBeTrue();
    $response->assertJsonMissing(['id' => $foreign->id]);
});

it('hides invitations from members', function () {
    [, $acme] = userInOrganization();
    Sanctum::actingAs(addMember($acme, OrganizationRole::Member));

    $this->getJson('/api/invitations')->assertForbidden();
});

it('lets owners and admins revoke an invitation', function (OrganizationRole $role) {
    [$owner, $acme] = userInOrganization();
    $actor = $role === OrganizationRole::Owner ? $owner : addMember($acme, $role);
    [$invitation] = createInvitation($acme, 'new@example.com');
    Sanctum::actingAs($actor);

    $this->deleteJson("/api/invitations/{$invitation->id}")->assertNoContent();

    expect(OrganizationInvitation::withoutGlobalScopes()->find($invitation->id))->toBeNull();
})->with([OrganizationRole::Owner, OrganizationRole::Admin]);

it('does not let members revoke invitations', function () {
    [, $acme] = userInOrganization();
    [$invitation] = createInvitation($acme, 'new@example.com');
    Sanctum::actingAs(addMember($acme, OrganizationRole::Member));

    $this->deleteJson("/api/invitations/{$invitation->id}")->assertForbidden();

    expect(OrganizationInvitation::withoutGlobalScopes()->find($invitation->id))->not->toBeNull();
});

it('cannot revoke another organization\'s invitation', function () {
    [$owner] = userInOrganization(OrganizationRole::Owner, 'Acme');
    [, $otherOrganization] = userInOrganization(OrganizationRole::Owner, 'Other Corp');
    [$foreign] = createInvitation($otherOrganization, 'new@example.com');
    Sanctum::actingAs($owner);

    $this->deleteJson("/api/invitations/{$foreign->id}")->assertNotFound();

    expect(OrganizationInvitation::withoutGlobalScopes()->find($foreign->id))->not->toBeNull();
});
