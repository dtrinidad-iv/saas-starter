<?php

use App\Enums\ChatMessageRole;
use App\Enums\DefaultRole;
use App\Enums\ProjectSummaryStatus;
use App\Models\AiUsage;
use App\Models\Project;
use App\Models\ProjectChatMessage;
use App\Support\CurrentOrganization;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->ownerA, $this->orgA] = userInOrganization(DefaultRole::Owner, 'Org A');
    [$this->ownerB, $this->orgB] = userInOrganization(DefaultRole::Owner, 'Org B');

    $this->projectA = inOrganization($this->orgA, fn () => Project::factory()->create(['name' => 'A plan', 'description' => 'A only']));
    $this->projectB = inOrganization($this->orgB, fn () => Project::factory()->create(['name' => 'B secret', 'description' => 'B confidential roadmap']));
    createSummary($this->orgB, $this->projectB, ProjectSummaryStatus::Approved, ['summary' => 'B approved summary']);

    createChatMessage($this->orgB, $this->projectB, $this->ownerB, ChatMessageRole::User, 'B private question');
    createChatMessage($this->orgB, $this->projectB, $this->ownerB, ChatMessageRole::Assistant, 'B private answer');

    // Org A's owner is the attacker in every test.
    Sanctum::actingAs($this->ownerA);
});

it('cannot read, ask about or clear the chat of another organization\'s project', function () {
    $this->getJson("/api/projects/{$this->projectB->id}/chat")->assertNotFound();
    $this->postJson("/api/projects/{$this->projectB->id}/chat", ['message' => 'Hi'])->assertNotFound();
    $this->deleteJson("/api/projects/{$this->projectB->id}/chat")->assertNotFound();

    $this->ai->assertNothingSent();
    expect(ProjectChatMessage::withoutGlobalScopes()->where('organization_id', $this->orgB->id)->count())->toBe(2)
        ->and(AiUsage::withoutGlobalScopes()->count())->toBe(0);
});

it('never puts another organization\'s data in the prompt', function () {
    $this->postJson("/api/projects/{$this->projectA->id}/chat", ['message' => 'Tell me about B secret'])->assertOk()->streamedContent();

    $request = collect($this->ai->sent())->sole();
    expect($request->system)->toContain('A only')
        ->and(json_encode([$request->system, $request->messages]))
        ->not->toContain('B confidential')
        ->not->toContain('B approved summary')
        ->not->toContain('B private');
});

it('saves its messages and usage in its own organization', function () {
    $this->postJson("/api/projects/{$this->projectA->id}/chat", ['message' => 'Hi'])->assertOk()->streamedContent();

    expect(ProjectChatMessage::withoutGlobalScopes()->where('project_id', $this->projectA->id)->pluck('organization_id')->unique()->all())->toBe([$this->orgA->id])
        ->and(AiUsage::withoutGlobalScopes()->sole()->organization_id)->toBe($this->orgA->id);
});

it('does not clear another organization\'s messages when clearing its own', function () {
    createChatMessage($this->orgA, $this->projectA, $this->ownerA, ChatMessageRole::User, 'A question');

    $this->deleteJson("/api/projects/{$this->projectA->id}/chat")->assertNoContent();

    expect(ProjectChatMessage::withoutGlobalScopes()->pluck('content')->all())->toBe(['B private question', 'B private answer']);
});

it('saves the answer in the organization that authorized it, even if the user switches while it streams', function () {
    $this->orgB->members()->attach($this->ownerA, ['role_id' => roleOf($this->orgB, DefaultRole::Owner)->id]);

    $response = $this->postJson("/api/projects/{$this->projectA->id}/chat", ['message' => 'Hi'])->assertOk();

    $this->ownerA->forceFill(['current_organization_id' => $this->orgB->id])->save();
    app(CurrentOrganization::class)->forget();
    $response->streamedContent();

    expect(ProjectChatMessage::withoutGlobalScopes()->where('project_id', $this->projectA->id)->pluck('organization_id')->all())
        ->toBe([$this->orgA->id, $this->orgA->id]);
});

it('gives 404 for the other organization\'s project to a member of both until they switch', function () {
    $this->orgB->members()->attach($this->ownerA, ['role_id' => roleOf($this->orgB, DefaultRole::Owner)->id]);

    $this->getJson("/api/projects/{$this->projectB->id}/chat")->assertNotFound();
    $this->postJson("/api/projects/{$this->projectB->id}/chat", ['message' => 'Hi'])->assertNotFound();
    $this->deleteJson("/api/projects/{$this->projectB->id}/chat")->assertNotFound();

    $this->ai->assertNothingSent();
    expect(ProjectChatMessage::withoutGlobalScopes()->where('organization_id', $this->orgB->id)->count())->toBe(2)
        ->and(AiUsage::withoutGlobalScopes()->count())->toBe(0);
});
