<?php

use App\Enums\DefaultRole;
use App\Enums\Permission;
use App\Enums\ProjectSummaryStatus;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectSummary;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->owner, $this->acme] = userInOrganization();
});

/**
 * Overwrite a role's stored permissions directly, like an older organization's role.
 *
 * @param  list<string>  $permissions
 */
function setRolePermissions(Organization $organization, DefaultRole $role, array $permissions): void
{
    DB::table('roles')->where('id', roleOf($organization, $role)->id)->update(['permissions' => json_encode($permissions)]);
}

it('lets only roles with ai_usage.view read the usage report', function (DefaultRole $role, int $status) {
    Sanctum::actingAs(addMember($this->acme, $role));

    $this->getJson('/api/ai-usage')->assertStatus($status);
})->with([
    'owner' => [DefaultRole::Owner, 200],
    'admin' => [DefaultRole::Admin, 200],
    'member' => [DefaultRole::Member, 403],
]);

it('lets only roles with ai_budget.manage change the budget', function (DefaultRole $role, int $status) {
    Sanctum::actingAs(addMember($this->acme, $role));

    $this->putJson('/api/ai-budget', ['monthly_budget_usd' => 3])->assertStatus($status);

    expect($this->acme->fresh()->ai_monthly_budget_usd)->toBe($status === 200 ? '3.00' : null);
})->with([
    'owner' => [DefaultRole::Owner, 200],
    'admin' => [DefaultRole::Admin, 403],
    'member' => [DefaultRole::Member, 403],
]);

it('tells the report viewer whether they can manage the budget', function (DefaultRole $role, bool $canManage) {
    Sanctum::actingAs(addMember($this->acme, $role));

    $this->getJson('/api/ai-usage')->assertOk()->assertJsonPath('data.budget.can_manage', $canManage);
})->with([
    'owner' => [DefaultRole::Owner, true],
    'admin' => [DefaultRole::Admin, false],
]);

it('follows the AI permissions on custom roles', function () {
    $analyst = createRole($this->acme, 'Analyst', [Permission::AiUsageView]);
    $treasurer = createRole($this->acme, 'Treasurer', [Permission::AiBudgetManage]);
    $viewer = createRole($this->acme, 'Viewer', [Permission::ProjectsView]);

    Sanctum::actingAs(addMember($this->acme, $analyst));
    $this->getJson('/api/ai-usage')->assertOk()->assertJsonPath('data.budget.can_manage', false);
    $this->putJson('/api/ai-budget', ['monthly_budget_usd' => 1])->assertForbidden();

    Sanctum::actingAs(addMember($this->acme, $treasurer));
    $this->putJson('/api/ai-budget', ['monthly_budget_usd' => 3])->assertOk();
    $this->getJson('/api/ai-usage')->assertForbidden();

    Sanctum::actingAs(addMember($this->acme, $viewer));
    $this->getJson('/api/ai-usage')->assertForbidden();
    $this->putJson('/api/ai-budget', ['monthly_budget_usd' => 1])->assertForbidden();

    expect($this->acme->fresh()->ai_monthly_budget_usd)->toBe('3.00');
});

it('needs authentication', function () {
    $this->getJson('/api/ai-usage')->assertUnauthorized();
    $this->putJson('/api/ai-budget', ['monthly_budget_usd' => 1])->assertUnauthorized();
});

it('needs a current organization', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/ai-usage')->assertForbidden();
    $this->putJson('/api/ai-budget', ['monthly_budget_usd' => 1])->assertForbidden();
});

it('refuses to regenerate a summary for a role that can edit the project but lacks ai.use', function () {
    $editor = createRole($this->acme, 'Editor', [Permission::ProjectsView, Permission::ProjectsUpdateAny]);
    $project = inOrganization($this->acme, fn () => Project::factory()->create(['description' => 'Something']));
    Sanctum::actingAs(addMember($this->acme, $editor));

    $this->postJson("/api/projects/{$project->id}/summary")->assertForbidden();

    expect(ProjectSummary::withoutGlobalScopes()->count())->toBe(0);
    $this->ai->assertNothingSent();
});

it('lets the same role regenerate once it has ai.use', function () {
    $editor = createRole($this->acme, 'Editor', [Permission::ProjectsView, Permission::ProjectsUpdateAny, Permission::AiUse]);
    $project = inOrganization($this->acme, fn () => Project::factory()->create(['description' => 'Something']));
    Sanctum::actingAs(addMember($this->acme, $editor));

    $this->postJson("/api/projects/{$project->id}/summary")->assertStatus(202);

    expect(ProjectSummary::withoutGlobalScopes()->where('project_id', $project->id)->count())->toBe(1);
});

it('refuses a member their own project\'s summary when the Member role lost ai.use', function () {
    setRolePermissions($this->acme, DefaultRole::Member, ['projects.view', 'projects.create', 'projects.update_own']);
    $member = addMember($this->acme, DefaultRole::Member);
    $project = inOrganization($this->acme, function () use ($member) {
        $project = Project::factory()->make(['description' => 'Something']);
        $project->created_by = $member->id;
        $project->save();

        return $project;
    });
    $draft = createSummary($this->acme, $project, ProjectSummaryStatus::Draft);
    Sanctum::actingAs($member);

    // Reviewing still follows project update; only generating needs ai.use.
    $this->getJson("/api/projects/{$project->id}/summary")
        ->assertOk()
        ->assertJsonPath('data.can', ['review' => true, 'regenerate' => false]);
    $this->postJson("/api/projects/{$project->id}/summary")->assertForbidden();
    $this->postJson("/api/projects/{$project->id}/summaries/{$draft->id}/reject")->assertOk();

    $this->ai->assertNothingSent();
});

it('gives new organizations\' default roles the AI permissions', function () {
    $admin = roleOf($this->acme, DefaultRole::Admin);
    $member = roleOf($this->acme, DefaultRole::Member);

    expect($admin->has(Permission::AiUse))->toBeTrue()
        ->and($admin->has(Permission::AiUsageView))->toBeTrue()
        ->and($admin->has(Permission::AiBudgetManage))->toBeFalse()
        ->and($member->has(Permission::AiUse))->toBeTrue()
        ->and($member->has(Permission::AiUsageView))->toBeFalse()
        ->and($member->has(Permission::AiBudgetManage))->toBeFalse()
        ->and(roleOf($this->acme, DefaultRole::Owner)->has(Permission::AiBudgetManage))->toBeTrue();
});

it('lists the AI permissions in the catalogue', function () {
    Sanctum::actingAs($this->owner);

    $this->getJson('/api/permissions')
        ->assertOk()
        ->assertJsonFragment(['value' => 'ai.use', 'group' => 'AI', 'label' => 'Use AI features (summaries, writing help, chat)'])
        ->assertJsonFragment(['value' => 'ai_usage.view', 'group' => 'AI', 'label' => 'View AI usage and cost'])
        ->assertJsonFragment(['value' => 'ai_budget.manage', 'group' => 'AI', 'label' => 'Change the monthly AI budget']);
});

it('grants ai.use to existing Admin and Member roles and ai_usage.view to Admin only', function () {
    $admin = roleOf($this->acme, DefaultRole::Admin);
    $member = roleOf($this->acme, DefaultRole::Member);
    $renamed = createRole($this->acme, 'Administrators', [Permission::ProjectsView]);
    setRolePermissions($this->acme, DefaultRole::Admin, ['projects.view']);
    setRolePermissions($this->acme, DefaultRole::Member, ['projects.view']);

    $migration = require database_path('migrations/2026_10_21_090001_grant_ai_permissions_to_default_roles.php');
    $migration->up();
    $migration->up(); // idempotent

    expect($admin->fresh()->permissions->map->value->all())->toBe(['projects.view', 'ai.use', 'ai_usage.view'])
        ->and($member->fresh()->permissions->map->value->all())->toBe(['projects.view', 'ai.use'])
        ->and($renamed->fresh()->permissions->map->value->all())->toBe(['projects.view'])
        ->and($admin->fresh()->has(Permission::AiBudgetManage))->toBeFalse();
});

it('removes the AI grants from Admin and Member roles on rollback', function () {
    $migration = require database_path('migrations/2026_10_21_090001_grant_ai_permissions_to_default_roles.php');
    $migration->down();

    expect(roleOf($this->acme, DefaultRole::Admin)->has(Permission::AiUse))->toBeFalse()
        ->and(roleOf($this->acme, DefaultRole::Admin)->has(Permission::AiUsageView))->toBeFalse()
        ->and(roleOf($this->acme, DefaultRole::Admin)->has(Permission::ProjectsView))->toBeTrue()
        ->and(roleOf($this->acme, DefaultRole::Member)->has(Permission::AiUse))->toBeFalse();
});
