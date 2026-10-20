<?php

use App\Enums\OrganizationRole;
use App\Mail\OrganizationInvitationMail;
use App\Models\OrganizationInvitation;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

beforeEach(fn () => Mail::fake());

it('applies the invite permissions', function (OrganizationRole $actorRole, OrganizationRole $invitedRole, bool $allowed) {
    [$owner, $acme] = userInOrganization(OrganizationRole::Owner);
    $actor = $actorRole === OrganizationRole::Owner ? $owner : addMember($acme, $actorRole);
    Sanctum::actingAs($actor);

    $response = $this->postJson('/api/invitations', ['email' => 'new@example.com', 'role' => $invitedRole->value]);

    if ($allowed) {
        $response->assertCreated()->assertJsonPath('data.role', $invitedRole->value);
        Mail::assertQueued(OrganizationInvitationMail::class);
    } else {
        $response->assertForbidden();
        Mail::assertNothingQueued();
    }
})->with([
    'owner invites member' => [OrganizationRole::Owner, OrganizationRole::Member, true],
    'owner invites owner' => [OrganizationRole::Owner, OrganizationRole::Owner, true],
    'admin invites admin' => [OrganizationRole::Admin, OrganizationRole::Admin, true],
    'admin invites owner' => [OrganizationRole::Admin, OrganizationRole::Owner, false],
    'member invites member' => [OrganizationRole::Member, OrganizationRole::Member, false],
]);

it('queues an email with a working link and stores only a hash of the token', function () {
    [$owner, $acme] = userInOrganization(OrganizationRole::Owner, 'Acme');
    Sanctum::actingAs($owner);

    $this->postJson('/api/invitations', ['email' => 'New.Person@Example.com', 'role' => 'admin'])
        ->assertCreated()
        ->assertJsonPath('data.email', 'new.person@example.com')
        ->assertJsonPath('data.invited_by.id', $owner->id)
        ->assertJsonMissingPath('data.token')
        ->assertJsonMissingPath('data.token_hash');

    $sent = null;
    Mail::assertQueued(OrganizationInvitationMail::class, function (OrganizationInvitationMail $mail) use (&$sent) {
        $sent = $mail;

        return $mail->hasTo('new.person@example.com');
    });

    expect($sent->organizationName)->toBe('Acme')
        ->and($sent->role)->toBe(OrganizationRole::Admin)
        ->and($sent->url)->toStartWith(config('app.frontend_url').'/join/');

    $token = basename($sent->url);
    $invitation = OrganizationInvitation::withoutGlobalScopes()->sole();

    expect($invitation->organization_id)->toBe($acme->id)
        ->and($invitation->token_hash)->toBe(hash('sha256', $token))
        ->and($invitation->token_hash)->not->toContain($token)
        ->and($invitation->expires_at->isAfter(now()->addDays(6)))->toBeTrue();

    $sent->assertSeeInHtml($sent->url);
    $sent->assertSeeInText('Acme');
});

it('replaces a pending invitation when the same email is invited again', function () {
    [$owner] = userInOrganization();
    Sanctum::actingAs($owner);

    $first = $this->postJson('/api/invitations', ['email' => 'new@example.com', 'role' => 'member'])->json('data.id');
    $second = $this->postJson('/api/invitations', ['email' => 'new@example.com', 'role' => 'admin'])->json('data.id');

    expect(OrganizationInvitation::withoutGlobalScopes()->pluck('id')->all())->toBe([$second])
        ->and($second)->not->toBe($first);
    Mail::assertQueuedCount(2);
});

it('refuses to invite an existing member', function () {
    [$owner, $acme] = userInOrganization();
    addMember($acme, attributes: ['email' => 'member@example.com']);
    Sanctum::actingAs($owner);

    $this->postJson('/api/invitations', ['email' => 'MEMBER@example.com', 'role' => 'member'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);

    Mail::assertNothingQueued();
});

it('validates email and role', function () {
    Sanctum::actingAs(userInOrganization()[0]);

    $this->postJson('/api/invitations', ['email' => 'nope', 'role' => 'king'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'role']);
});

it('throttles invitations', function () {
    Sanctum::actingAs(userInOrganization()[0]);

    foreach (range(1, 20) as $i) {
        $this->postJson('/api/invitations', ['email' => "person{$i}@example.com", 'role' => 'member'])->assertCreated();
    }

    $this->postJson('/api/invitations', ['email' => 'one-more@example.com', 'role' => 'member'])->assertTooManyRequests();
});
