<?php

use App\Enums\DefaultRole;
use App\Mail\OrganizationInvitationMail;
use App\Models\OrganizationInvitation;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

beforeEach(fn () => Mail::fake());

it('applies the invite permissions', function (DefaultRole $actorRole, DefaultRole $invitedRole, bool $allowed) {
    [$owner, $acme] = userInOrganization(DefaultRole::Owner);
    $actor = $actorRole === DefaultRole::Owner ? $owner : addMember($acme, $actorRole);
    Sanctum::actingAs($actor);

    $response = $this->postJson('/api/invitations', ['email' => 'new@example.com', 'role_id' => roleOf($acme, $invitedRole)->id]);

    if ($allowed) {
        $response->assertCreated()->assertJsonPath('data.role.name', $invitedRole->value);
        Mail::assertQueued(OrganizationInvitationMail::class);
    } else {
        $response->assertForbidden();
        Mail::assertNothingQueued();
    }
})->with([
    'owner invites member' => [DefaultRole::Owner, DefaultRole::Member, true],
    'owner invites owner' => [DefaultRole::Owner, DefaultRole::Owner, true],
    'admin invites admin' => [DefaultRole::Admin, DefaultRole::Admin, true],
    'admin invites owner' => [DefaultRole::Admin, DefaultRole::Owner, false],
    'member invites member' => [DefaultRole::Member, DefaultRole::Member, false],
]);

it('queues an email with a working link and stores only a hash of the token', function () {
    [$owner, $acme] = userInOrganization(DefaultRole::Owner, 'Acme');
    Sanctum::actingAs($owner);

    $this->postJson('/api/invitations', ['email' => 'New.Person@Example.com', 'role_id' => roleOf($acme, DefaultRole::Admin)->id])
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
        ->and($sent->roleName)->toBe('Admin')
        ->and($sent->url)->toStartWith(config('app.frontend_url').'/join/');

    $token = basename($sent->url);
    $invitation = OrganizationInvitation::withoutGlobalScopes()->sole();

    expect($invitation->organization_id)->toBe($acme->id)
        ->and($invitation->token_hash)->toBe(hash('sha256', $token))
        ->and($invitation->token_hash)->not->toContain($token)
        ->and($invitation->expires_at->isAfter(now()->addDays(6)))->toBeTrue();

    $sent->assertSeeInHtml($sent->url);
    $sent->assertSeeInText('Acme');
    $sent->assertSeeInText('Admin');
});

it('replaces a pending invitation when the same email is invited again', function () {
    [$owner, $acme] = userInOrganization();
    Sanctum::actingAs($owner);

    $first = $this->postJson('/api/invitations', ['email' => 'new@example.com', 'role_id' => roleOf($acme, DefaultRole::Member)->id])->json('data.id');
    $second = $this->postJson('/api/invitations', ['email' => 'new@example.com', 'role_id' => roleOf($acme, DefaultRole::Admin)->id])->json('data.id');

    expect(OrganizationInvitation::withoutGlobalScopes()->pluck('id')->all())->toBe([$second])
        ->and($second)->not->toBe($first);
    Mail::assertQueuedCount(2);
});

it('refuses to invite an existing member', function () {
    [$owner, $acme] = userInOrganization();
    addMember($acme, attributes: ['email' => 'member@example.com']);
    Sanctum::actingAs($owner);

    $this->postJson('/api/invitations', ['email' => 'MEMBER@example.com', 'role_id' => roleOf($acme, DefaultRole::Member)->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);

    Mail::assertNothingQueued();
});

it('validates email and role', function () {
    Sanctum::actingAs(userInOrganization()[0]);

    $this->postJson('/api/invitations', ['email' => 'nope', 'role_id' => 'king'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'role_id']);
});

it('rejects a role from another organization', function () {
    [$owner] = userInOrganization(DefaultRole::Owner, 'Acme');
    [, $otherOrganization] = userInOrganization(DefaultRole::Owner, 'Other Corp');
    Sanctum::actingAs($owner);

    $this->postJson('/api/invitations', ['email' => 'new@example.com', 'role_id' => roleOf($otherOrganization, DefaultRole::Admin)->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['role_id']);

    Mail::assertNothingQueued();
});

it('throttles invitations', function () {
    [$owner, $acme] = userInOrganization();
    Sanctum::actingAs($owner);

    foreach (range(1, 20) as $i) {
        $this->postJson('/api/invitations', ['email' => "person{$i}@example.com", 'role_id' => roleOf($acme, DefaultRole::Member)->id])->assertCreated();
    }

    $this->postJson('/api/invitations', ['email' => 'one-more@example.com', 'role_id' => roleOf($acme, DefaultRole::Member)->id])->assertTooManyRequests();
});

it('does not let names inject Markdown links into the email', function () {
    [$owner, $acme] = userInOrganization(DefaultRole::Owner, '[Acme](https://evil.example/org)');
    $role = createRole($acme, '[Verify your account](https://evil.example)', []);
    Sanctum::actingAs($owner);

    $this->postJson('/api/invitations', ['email' => 'new@example.com', 'role_id' => $role->id])->assertCreated();

    Mail::assertQueued(OrganizationInvitationMail::class, function (OrganizationInvitationMail $mail) {
        $html = $mail->render();

        expect($html)->not->toContain('href="https://evil.example')
            ->and($html)->toContain('[Verify your account](https://evil.example)');

        return true;
    });
});
