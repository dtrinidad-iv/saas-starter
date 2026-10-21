<?php

use App\Enums\AuditAction;
use App\Enums\DefaultRole;
use Illuminate\Support\Arr;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['ai.monthly_budget_usd' => 5]);
    $this->travelTo('2026-10-21 12:00:00');
    [$this->owner, $this->acme] = userInOrganization(name: 'Acme');
    $this->owner->forceFill(['name' => 'Olivia Owner'])->save();
    $this->member = addMember($this->acme, DefaultRole::Member, ['name' => 'Max Member']);
    Sanctum::actingAs($this->owner);
});

it('totals this month\'s calls, failures, tokens and cost', function () {
    createAiUsage($this->acme, ['user_id' => $this->owner->id, 'input_tokens' => 100, 'output_tokens' => 40, 'cost_usd' => '0.500000', 'created_at' => '2026-10-02 08:00:00']);
    createAiUsage($this->acme, ['user_id' => $this->member->id, 'input_tokens' => 200, 'output_tokens' => 60, 'cost_usd' => '0.250000', 'created_at' => '2026-10-20 09:00:00']);
    createAiUsage($this->acme, ['user_id' => $this->member->id, 'input_tokens' => 0, 'output_tokens' => 0, 'cost_usd' => null, 'status' => 'failed', 'error_code' => 'http_500', 'created_at' => '2026-10-20 10:00:00']);
    // Last month: not in this report.
    createAiUsage($this->acme, ['cost_usd' => '9.000000', 'created_at' => '2026-09-30 23:59:59']);

    $this->getJson('/api/ai-usage')
        ->assertOk()
        ->assertJsonPath('data.month', '2026-10')
        ->assertJsonPath('data.totals', [
            'calls' => 3,
            'failed' => 1,
            'input_tokens' => 300,
            'output_tokens' => 100,
            'cost_usd' => 0.75,
        ]);
});

it('returns zeros and empty breakdowns for a month without usage', function () {
    $this->getJson('/api/ai-usage')
        ->assertOk()
        ->assertJsonPath('data.totals', ['calls' => 0, 'failed' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'cost_usd' => 0])
        ->assertJsonPath('data.by_feature', [])
        ->assertJsonPath('data.by_user', [])
        ->assertJsonPath('data.by_day', [])
        ->assertJsonPath('data.budget.spent_usd', 0)
        ->assertJsonPath('data.budget.remaining_usd', 5);
});

it('breaks usage down by feature', function () {
    createAiUsage($this->acme, ['feature' => 'project_summary', 'cost_usd' => '0.100000']);
    createAiUsage($this->acme, ['feature' => 'project_summary', 'cost_usd' => '0.200000']);
    createAiUsage($this->acme, ['feature' => 'chat', 'cost_usd' => '0.050000', 'status' => 'failed']);

    $byFeature = $this->getJson('/api/ai-usage')->assertOk()->json('data.by_feature');

    expect($byFeature)->toBe([
        ['feature' => 'chat', 'calls' => 1, 'failed' => 1, 'input_tokens' => 100, 'output_tokens' => 50, 'cost_usd' => 0.05],
        ['feature' => 'project_summary', 'calls' => 2, 'failed' => 0, 'input_tokens' => 200, 'output_tokens' => 100, 'cost_usd' => 0.3],
    ]);
});

it('breaks usage down by user, most expensive first, with work that has no user', function () {
    createAiUsage($this->acme, ['user_id' => $this->owner->id, 'cost_usd' => '0.100000']);
    createAiUsage($this->acme, ['user_id' => $this->member->id, 'cost_usd' => '0.300000']);
    createAiUsage($this->acme, ['user_id' => $this->member->id, 'cost_usd' => '0.200000']);
    createAiUsage($this->acme, ['user_id' => null, 'cost_usd' => '0.010000']);

    $byUser = $this->getJson('/api/ai-usage')->assertOk()->json('data.by_user');

    expect($byUser)->toHaveCount(3)
        ->and($byUser[0])->toMatchArray(['user' => ['id' => $this->member->id, 'name' => 'Max Member'], 'calls' => 2, 'cost_usd' => 0.5])
        ->and($byUser[1])->toMatchArray(['user' => ['id' => $this->owner->id, 'name' => 'Olivia Owner'], 'calls' => 1, 'cost_usd' => 0.1])
        ->and($byUser[2])->toMatchArray(['user' => null, 'calls' => 1, 'cost_usd' => 0.01]);
});

it('breaks usage down by UTC day in order', function () {
    createAiUsage($this->acme, ['cost_usd' => '0.100000', 'created_at' => '2026-10-20 23:59:59']);
    createAiUsage($this->acme, ['cost_usd' => '0.200000', 'created_at' => '2026-10-01 00:00:00']);
    createAiUsage($this->acme, ['cost_usd' => '0.300000', 'created_at' => '2026-10-20 00:00:00']);

    $byDay = $this->getJson('/api/ai-usage')->assertOk()->json('data.by_day');

    expect(collect($byDay)->map(fn (array $row) => Arr::only($row, ['day', 'calls', 'cost_usd']))->all())->toBe([
        ['day' => '2026-10-01', 'calls' => 1, 'cost_usd' => 0.2],
        ['day' => '2026-10-20', 'calls' => 2, 'cost_usd' => 0.4],
    ]);
});

it('reports an earlier month when asked, while the budget stays on the current month', function () {
    createAiUsage($this->acme, ['cost_usd' => '1.000000', 'created_at' => '2026-09-01 00:00:00']);
    createAiUsage($this->acme, ['cost_usd' => '2.000000', 'created_at' => '2026-09-30 23:59:59']);
    createAiUsage($this->acme, ['cost_usd' => '0.500000', 'created_at' => '2026-10-01 00:00:00']);
    createAiUsage($this->acme, ['cost_usd' => '7.000000', 'created_at' => '2026-08-31 23:59:59']);

    $this->getJson('/api/ai-usage?month=2026-09')
        ->assertOk()
        ->assertJsonPath('data.month', '2026-09')
        ->assertJsonPath('data.totals.calls', 2)
        ->assertJsonPath('data.totals.cost_usd', 3)
        ->assertJsonPath('data.by_day.0.day', '2026-09-01')
        ->assertJsonPath('data.by_day.1.day', '2026-09-30')
        ->assertJsonPath('data.budget.spent_usd', 0.5)
        ->assertJsonPath('data.budget.remaining_usd', 4.5);
});

it('reports the current month by default', function () {
    $this->travelTo('2026-11-01 00:00:00');
    createAiUsage($this->acme, ['cost_usd' => '0.500000', 'created_at' => '2026-10-31 23:59:59']);

    $this->getJson('/api/ai-usage')
        ->assertOk()
        ->assertJsonPath('data.month', '2026-11')
        ->assertJsonPath('data.totals.calls', 0);
});

it('rejects an invalid month', function (string $month) {
    $this->getJson('/api/ai-usage?month='.urlencode($month))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('month');
})->with(['2026-13', '2026/10', 'october', '2026-1', '2026-10-01', '26-10']);

it('never reports a negative remaining budget', function () {
    createAiUsage($this->acme, ['cost_usd' => '7.250000']);

    $this->getJson('/api/ai-usage')
        ->assertOk()
        ->assertJsonPath('data.budget.limit_usd', 5)
        ->assertJsonPath('data.budget.spent_usd', 7.25)
        ->assertJsonPath('data.budget.remaining_usd', 0);
});

it('sets the monthly budget and returns the budget', function () {
    subscribe($this->acme);
    createAiUsage($this->acme, ['cost_usd' => '1.250000']);

    $this->putJson('/api/ai-budget', ['monthly_budget_usd' => 12.5])
        ->assertOk()
        ->assertExactJson(['data' => [
            'limit_usd' => 12.5,
            'is_default' => false,
            'own_limit_usd' => 12.5,
            'plan' => 'pro',
            'plan_limit_usd' => 50,
            'credits_usd' => 0,
            'spent_usd' => 1.25,
            'remaining_usd' => 11.25,
            'can_manage' => true,
        ]]);

    expect($this->acme->fresh()->ai_monthly_budget_usd)->toBe('12.50');
});

it('accepts 0 to turn AI off and null to go back to the default', function () {
    $this->putJson('/api/ai-budget', ['monthly_budget_usd' => 0])
        ->assertOk()
        ->assertJsonPath('data.limit_usd', 0)
        ->assertJsonPath('data.is_default', false);
    expect($this->acme->fresh()->ai_monthly_budget_usd)->toBe('0.00');

    $this->putJson('/api/ai-budget', ['monthly_budget_usd' => null])
        ->assertOk()
        ->assertJsonPath('data.limit_usd', 5)
        ->assertJsonPath('data.is_default', true);
    expect($this->acme->fresh()->ai_monthly_budget_usd)->toBeNull();
});

it('accepts the boundary values', function (int|float|string $value, string $stored) {
    $this->putJson('/api/ai-budget', ['monthly_budget_usd' => $value])->assertOk();

    expect($this->acme->fresh()->ai_monthly_budget_usd)->toBe($stored);
})->with([
    'the plan\'s budget' => [5, '5.00'],
    'two decimals' => [0.01, '0.01'],
    'numeric string' => ['4.5', '4.50'],
]);

it('validates the budget', function (array $payload) {
    $this->acme->forceFill(['ai_monthly_budget_usd' => 3])->save();

    $this->putJson('/api/ai-budget', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('monthly_budget_usd');

    expect($this->acme->fresh()->ai_monthly_budget_usd)->toBe('3.00')
        ->and(auditLogOf($this->acme)->where('action', AuditAction::AiBudgetUpdated))->toBeEmpty();
})->with([
    'missing' => [[]],
    'negative' => [['monthly_budget_usd' => -1]],
    'over the plan\'s budget' => [['monthly_budget_usd' => 5.01]],
    'three decimals' => [['monthly_budget_usd' => 1.255]],
    'not a number' => [['monthly_budget_usd' => 'lots']],
    'array' => [['monthly_budget_usd' => [5]]],
]);

it('records an audit entry with the old and new budget', function () {
    $this->putJson('/api/ai-budget', ['monthly_budget_usd' => 4])->assertOk();
    $this->putJson('/api/ai-budget', ['monthly_budget_usd' => null])->assertOk();

    $entries = auditLogOf($this->acme)->where('action', AuditAction::AiBudgetUpdated)->values();

    expect($entries)->toHaveCount(2)
        ->and($entries[0])
        ->actor_id->toBe($this->owner->id)
        ->subject_type->toBe('organization')
        ->subject_id->toBe($this->acme->id)
        ->changes->toEqual(['ai_monthly_budget_usd' => ['old' => null, 'new' => '4.00']])
        ->and($entries[1]->changes)->toEqual(['ai_monthly_budget_usd' => ['old' => '4.00', 'new' => null]]);
});

it('records no audit entry when the budget does not change', function () {
    $this->acme->forceFill(['ai_monthly_budget_usd' => 4])->save();

    $this->putJson('/api/ai-budget', ['monthly_budget_usd' => 4])->assertOk();
    $this->putJson('/api/ai-budget', ['monthly_budget_usd' => '4.00'])->assertOk();

    expect(auditLogOf($this->acme)->where('action', AuditAction::AiBudgetUpdated))->toBeEmpty()
        ->and($this->acme->fresh()->ai_monthly_budget_usd)->toBe('4.00');
});

it('records no audit entry when the budget stays on the default', function () {
    $this->putJson('/api/ai-budget', ['monthly_budget_usd' => null])->assertOk();

    expect(auditLogOf($this->acme)->where('action', AuditAction::AiBudgetUpdated))->toBeEmpty();
});
