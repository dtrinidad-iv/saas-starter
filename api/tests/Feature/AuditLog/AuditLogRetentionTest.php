<?php

use App\Models\AuditLog;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    [, $this->orgA] = userInOrganization(name: 'Org A');
    [, $this->orgB] = userInOrganization(name: 'Org B');

    foreach ([$this->orgA, $this->orgB] as $organization) {
        inOrganization($organization, fn () => Project::factory()->count(2)->create());
    }
    // One old entry in each organization.
    DB::table('audit_logs')->whereIn('id', [auditLogOf($this->orgA)->first()->id, auditLogOf($this->orgB)->first()->id])
        ->update(['created_at' => now()->subDays(400)]);
});

it('prunes entries older than the retention in every organization', function () {
    config(['audit.retention_days' => 365]);

    $this->artisan('model:prune', ['--model' => [AuditLog::class]])->assertSuccessful();

    expect(auditLogOf($this->orgA))->toHaveCount(1)
        ->and(auditLogOf($this->orgB))->toHaveCount(1);
});

it('keeps everything when the retention is 0', function () {
    config(['audit.retention_days' => 0]);

    $this->artisan('model:prune', ['--model' => [AuditLog::class]])->assertSuccessful();

    expect(auditLogOf($this->orgA))->toHaveCount(2)
        ->and(auditLogOf($this->orgB))->toHaveCount(2);
});

it('does not let entries be changed or deleted one by one', function () {
    $entry = auditLogOf($this->orgA)->first();

    expect(fn () => inOrganization($this->orgA, fn () => $entry->forceFill(['subject_label' => 'Edited'])->save()))
        ->toThrow(LogicException::class, 'cannot be changed')
        ->and(fn () => inOrganization($this->orgA, fn () => $entry->delete()))
        ->toThrow(LogicException::class, 'cannot be deleted');
});

it('is scheduled daily', function () {
    $this->artisan('schedule:list')->expectsOutputToContain('model:prune')->assertSuccessful();
});
