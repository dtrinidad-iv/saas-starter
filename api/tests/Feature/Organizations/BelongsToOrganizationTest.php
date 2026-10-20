<?php

use App\Enums\DefaultRole;
use App\Exceptions\NoCurrentOrganization;
use App\Models\Organization;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Fixtures\TenantNote;

beforeEach(function () {
    // Postgres DDL is transactional, so RefreshDatabase rolls this table back too.
    Schema::create('tenant_notes', function (Blueprint $table) {
        $table->id();
        $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
        $table->string('body');
        $table->timestamps();
    });

    [$this->userA, $this->orgA] = userInOrganization(DefaultRole::Owner, 'Org A');
    [$this->userB, $this->orgB] = userInOrganization(DefaultRole::Owner, 'Org B');

    // Seed one note per organization, each written while acting in that organization.
    actInOrganization($this->orgA);
    $this->noteA = TenantNote::create(['body' => 'A secret']);
    actInOrganization($this->orgB);
    $this->noteB = TenantNote::create(['body' => 'B secret']);
    app(CurrentOrganization::class)->forget();
});

function actInOrganization(Organization $organization): void
{
    app(CurrentOrganization::class)->set($organization);
}

it('only reads rows of the current organization', function () {
    Sanctum::actingAs($this->userA);

    expect(TenantNote::pluck('body')->all())->toBe(['A secret'])
        ->and(TenantNote::find($this->noteB->id))->toBeNull()
        ->and(TenantNote::count())->toBe(1);
});

it('cannot load another organization\'s row by id', function () {
    Sanctum::actingAs($this->userA);

    TenantNote::findOrFail($this->noteB->id);
})->throws(ModelNotFoundException::class);

it('cannot update or delete another organization\'s rows through queries', function () {
    Sanctum::actingAs($this->userA);

    expect(TenantNote::whereKey($this->noteB->id)->update(['body' => 'hacked']))->toBe(0)
        ->and(TenantNote::whereKey($this->noteB->id)->delete())->toBe(0);

    $stored = TenantNote::withoutGlobalScopes()->findOrFail($this->noteB->id);
    expect($stored->body)->toBe('B secret');
});

it('sets organization_id from the current organization on create, ignoring input', function () {
    Sanctum::actingAs($this->userA);

    $note = TenantNote::create(['body' => 'new', 'organization_id' => $this->orgB->id]);

    expect($note->organization_id)->toBe($this->orgA->id)
        ->and(TenantNote::withoutGlobalScopes()->where('organization_id', $this->orgB->id)->count())->toBe(1);
});

it('does not allow moving a row to another organization', function () {
    Sanctum::actingAs($this->userA);

    $note = TenantNote::findOrFail($this->noteA->id);
    $note->organization_id = $this->orgB->id;
    $note->save();
})->throws(LogicException::class);

it('returns no rows without a current organization', function () {
    Sanctum::actingAs(User::factory()->create());

    expect(TenantNote::count())->toBe(0);
});

it('returns no rows when nobody is authenticated', function () {
    expect(TenantNote::count())->toBe(0);
});

it('refuses to create without a current organization', function () {
    Sanctum::actingAs(User::factory()->create());

    TenantNote::create(['body' => 'orphan', 'organization_id' => $this->orgA->id]);
})->throws(NoCurrentOrganization::class);

it('stops showing an organization\'s rows once the user is removed from it', function () {
    $this->orgA->members()->detach($this->userA);
    Sanctum::actingAs($this->userA);

    expect(TenantNote::count())->toBe(0);
});

it('follows the user when they switch organizations', function () {
    $this->orgB->members()->attach($this->userA, ['role_id' => roleOf($this->orgB, DefaultRole::Member)->id]);
    Sanctum::actingAs($this->userA);

    expect(TenantNote::pluck('body')->all())->toBe(['A secret']);

    $this->postJson("/api/organizations/{$this->orgB->id}/switch")->assertOk();

    expect(TenantNote::pluck('body')->all())->toBe(['B secret']);
});
