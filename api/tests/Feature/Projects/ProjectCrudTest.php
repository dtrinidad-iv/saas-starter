<?php

use App\Events\ProjectCreated;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

it('creates a project in the current organization and dispatches ProjectCreated', function () {
    Event::fake([ProjectCreated::class]);
    [$owner, $acme] = userInOrganization();
    [, $otherOrganization] = userInOrganization(name: 'Other Corp');
    Sanctum::actingAs($owner);

    $response = $this->postJson('/api/projects', [
        'name' => 'Website relaunch',
        'description' => 'New marketing site',
        'organization_id' => $otherOrganization->id, // must be ignored
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Website relaunch')
        ->assertJsonPath('data.description', 'New marketing site')
        ->assertJsonPath('data.creator.id', $owner->id)
        ->assertJsonPath('data.can', ['update' => true, 'delete' => true]);

    $project = Project::withoutGlobalScopes()->findOrFail($response->json('data.id'));
    expect($project->organization_id)->toBe($acme->id)
        ->and($project->created_by)->toBe($owner->id);

    Event::assertDispatched(ProjectCreated::class, fn (ProjectCreated $event) => $event->project->is($project));
});

it('shows a project', function () {
    [$owner, $acme] = userInOrganization();
    $project = inOrganization($acme, fn () => Project::factory()->create(['name' => 'Alpha']));
    Sanctum::actingAs($owner);

    $this->getJson("/api/projects/{$project->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $project->id)
        ->assertJsonPath('data.name', 'Alpha');
});

it('updates a project', function () {
    [$owner, $acme] = userInOrganization();
    $project = inOrganization($acme, fn () => Project::factory()->create(['name' => 'Alpha']));
    Sanctum::actingAs($owner);

    $this->patchJson("/api/projects/{$project->id}", ['name' => 'Beta', 'description' => null])
        ->assertOk()
        ->assertJsonPath('data.name', 'Beta')
        ->assertJsonPath('data.description', null);

    expect($project->fresh()->name)->toBe('Beta');
});

it('keeps fields that are not sent on update', function () {
    [$owner, $acme] = userInOrganization();
    $project = inOrganization($acme, fn () => Project::factory()->create(['name' => 'Alpha', 'description' => 'Keep me']));
    Sanctum::actingAs($owner);

    $this->patchJson("/api/projects/{$project->id}", ['name' => 'Beta'])->assertOk();

    expect($project->fresh()->description)->toBe('Keep me');
});

it('deletes a project', function () {
    [$owner, $acme] = userInOrganization();
    $project = inOrganization($acme, fn () => Project::factory()->create());
    Sanctum::actingAs($owner);

    $this->deleteJson("/api/projects/{$project->id}")->assertNoContent();

    expect(Project::withoutGlobalScopes()->find($project->id))->toBeNull();
});

it('lists projects newest first, 20 per page', function () {
    [$owner, $acme] = userInOrganization();
    inOrganization($acme, function () {
        foreach (range(1, 25) as $i) {
            Project::factory()->create(['name' => "Project {$i}", 'created_at' => now()->subMinutes(100 - $i)]);
        }
    });
    Sanctum::actingAs($owner);

    $this->getJson('/api/projects')
        ->assertOk()
        ->assertJsonCount(20, 'data')
        ->assertJsonPath('data.0.name', 'Project 25')
        ->assertJsonPath('meta.total', 25)
        ->assertJsonPath('meta.last_page', 2);

    $this->getJson('/api/projects?page=2')
        ->assertOk()
        ->assertJsonCount(5, 'data')
        ->assertJsonPath('data.4.name', 'Project 1');
});

it('searches by name, case-insensitively, treating % and _ literally', function () {
    [$owner, $acme] = userInOrganization();
    inOrganization($acme, function () {
        Project::factory()->create(['name' => 'Website relaunch']);
        Project::factory()->create(['name' => 'Mobile APP']);
        Project::factory()->create(['name' => '100% done']);
    });
    Sanctum::actingAs($owner);

    expect($this->getJson('/api/projects?search=app')->json('data.*.name'))->toBe(['Mobile APP'])
        ->and($this->getJson('/api/projects?search=%25')->json('data.*.name'))->toBe(['100% done'])
        ->and($this->getJson('/api/projects?search=_')->json('data'))->toBe([]);
});

it('validates input', function () {
    Sanctum::actingAs(userInOrganization()[0]);

    $this->postJson('/api/projects', ['name' => '', 'description' => str_repeat('a', 5001)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'description']);

    $this->postJson('/api/projects', ['name' => str_repeat('a', 256)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

it('requires a unique name within the organization only', function () {
    [$owner, $acme] = userInOrganization();
    [, $otherOrganization] = userInOrganization(name: 'Other Corp');
    $existing = inOrganization($acme, fn () => Project::factory()->create(['name' => 'Alpha']));
    inOrganization($otherOrganization, fn () => Project::factory()->create(['name' => 'Beta']));
    Sanctum::actingAs($owner);

    $this->postJson('/api/projects', ['name' => 'Alpha'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    // Same name as another organization's project is fine.
    $this->postJson('/api/projects', ['name' => 'Beta'])->assertCreated();

    // Keeping its own name on update is fine.
    $this->patchJson("/api/projects/{$existing->id}", ['name' => 'Alpha'])->assertOk();
});

it('requires a current organization and authentication', function () {
    $this->getJson('/api/projects')->assertUnauthorized();

    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/projects')->assertForbidden();
    $this->postJson('/api/projects', ['name' => 'Orphan'])->assertForbidden();
});
