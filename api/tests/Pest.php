<?php

use App\Enums\ChatMessageRole;
use App\Enums\ChatMessageStatus;
use App\Enums\DefaultRole;
use App\Enums\Permission;
use App\Enums\ProjectSummaryStatus;
use App\Models\AiUsage;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\Project;
use App\Models\ProjectChatMessage;
use App\Models\ProjectSummary;
use App\Models\Role;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Cashier\Subscription;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * A user who is a member of a new organization (with the given role) and has it as current.
 *
 * @return array{0: User, 1: Organization}
 */
function userInOrganization(DefaultRole $role = DefaultRole::Owner, string $name = 'Acme'): array
{
    $user = User::factory()->create();
    $organization = Organization::factory()->withMember($user, $role)->create(['name' => $name]);
    $user->forceFill(['current_organization_id' => $organization->id])->save();

    return [$user, $organization];
}

/**
 * One of the organization's roles: a default one, or any role model of it.
 */
function roleOf(Organization $organization, DefaultRole|Role $role): Role
{
    if ($role instanceof Role) {
        return $role;
    }

    return Role::withoutGlobalScope(OrganizationScope::class)
        ->where('organization_id', $organization->id)
        ->where('name', $role->value)
        ->firstOrFail();
}

/**
 * Create a custom role in the organization.
 *
 * @param  list<Permission>  $permissions
 */
function createRole(Organization $organization, string $name, array $permissions): Role
{
    return inOrganization($organization, fn () => Role::create(['name' => $name, 'permissions' => $permissions]));
}

/**
 * The role a user currently has in the organization.
 */
function roleIn(Organization $organization, User $user): Role
{
    return Role::withoutGlobalScope(OrganizationScope::class)
        ->whereKey($organization->members()->findOrFail($user->id)->membership->role_id)
        ->firstOrFail();
}

/**
 * Add a new user to the organization with the given role (their current organization).
 */
function addMember(Organization $organization, DefaultRole|Role $role = DefaultRole::Member, array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $organization->members()->attach($user, ['role_id' => roleOf($organization, $role)->id]);
    $user->forceFill(['current_organization_id' => $organization->id])->save();

    return $user;
}

/**
 * Create an invitation directly (no email). Returns the invitation and its plain token.
 *
 * @return array{0: OrganizationInvitation, 1: string}
 */
function createInvitation(Organization $organization, string $email, DefaultRole|Role $role = DefaultRole::Member, array $attributes = []): array
{
    $token = Str::random(48);

    app(CurrentOrganization::class)->set($organization);
    // forceFill: tests may set non-fillable columns such as accepted_at.
    $invitation = (new OrganizationInvitation)->forceFill(array_merge([
        'email' => $email,
        'role_id' => roleOf($organization, $role)->id,
        'token_hash' => OrganizationInvitation::hashToken($token),
        'expires_at' => now()->addDays(7),
    ], $attributes));
    $invitation->save();
    app(CurrentOrganization::class)->forget();

    return [$invitation, $token];
}

/**
 * Run code acting in an organization (no logged-in user needed), e.g. to create
 * tenant-owned rows with factories. Returns what the callback returns.
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function inOrganization(Organization $organization, Closure $callback): mixed
{
    app(CurrentOrganization::class)->set($organization);

    try {
        return $callback();
    } finally {
        app(CurrentOrganization::class)->forget();
    }
}

/**
 * A summary row in a given state, created directly (no job, no AI call).
 *
 * @param  array<string, mixed>  $attributes
 */
function createSummary(Organization $organization, Project $project, ProjectSummaryStatus $status, array $attributes = []): ProjectSummary
{
    return inOrganization($organization, function () use ($project, $status, $attributes) {
        $summary = new ProjectSummary;
        $summary->forceFill(array_merge([
            'project_id' => $project->id,
            'status' => $status,
            'summary' => $status === ProjectSummaryStatus::Pending ? null : 'Existing summary.',
            'key_points' => [],
        ], $attributes))->save();

        return $summary;
    });
}

/**
 * An organization's audit log entries, oldest first.
 *
 * @return Collection<int, AuditLog>
 */
function auditLogOf(Organization $organization): Collection
{
    return AuditLog::withoutGlobalScope(OrganizationScope::class)
        ->where('organization_id', $organization->id)
        ->orderBy('id')
        ->get();
}

/**
 * An ai_usage row written directly (the model has no factory). Pass created_at to
 * place it in time; it defaults to now.
 *
 * @param  array<string, mixed>  $attributes
 */
function createAiUsage(Organization $organization, array $attributes = []): AiUsage
{
    return inOrganization($organization, function () use ($attributes) {
        $usage = new AiUsage;
        $usage->forceFill(array_merge([
            'feature' => 'project_summary',
            'model' => 'fake-model',
            'input_tokens' => 100,
            'output_tokens' => 50,
            'cost_usd' => '0.010000',
            'status' => 'succeeded',
        ], $attributes))->save();

        return $usage;
    });
}

/**
 * The server-sent events in a streamed response, in order.
 *
 * @return list<array{event: string, data: array<string, mixed>}>
 */
function sseEvents(TestResponse $response): array
{
    $events = [];

    foreach (array_filter(explode("\n\n", $response->streamedContent())) as $block) {
        preg_match('/^event: (.+)\ndata: (.+)$/s', $block, $match);
        $events[] = ['event' => $match[1], 'data' => json_decode($match[2], true)];
    }

    return $events;
}

/**
 * A chat message written directly (no AI call).
 *
 * @param  array<string, mixed>  $attributes
 */
function createChatMessage(Organization $organization, Project $project, User $user, ChatMessageRole $role, string $content, array $attributes = []): ProjectChatMessage
{
    return inOrganization($organization, function () use ($project, $user, $role, $content, $attributes) {
        $message = new ProjectChatMessage;
        $message->forceFill(array_merge([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'role' => $role,
            'content' => $content,
            'status' => ChatMessageStatus::Complete,
        ], $attributes))->save();

        return $message;
    });
}

/**
 * Make the organization a Stripe customer with a subscription, written directly
 * (no webhook). Defaults to an active Pro subscription.
 *
 * @param  array<string, mixed>  $attributes
 */
function subscribe(Organization $organization, array $attributes = []): Subscription
{
    if (! $organization->hasStripeId()) {
        $organization->forceFill(['stripe_id' => 'cus_'.Str::random(14)])->save();
    }

    $subscription = $organization->subscriptions()->create(array_merge([
        'type' => 'default',
        'stripe_id' => 'sub_'.Str::random(14),
        'stripe_status' => 'active',
        'stripe_price' => config('billing.plans.pro.price'),
        'quantity' => 1,
    ], $attributes));
    $organization->unsetRelation('subscriptions');

    return $subscription;
}

/**
 * Post a Stripe event to the webhook, signed like Stripe does (t=..., v1=HMAC-SHA256
 * of "t.payload" with the webhook secret). Pass $secret to sign with a wrong one.
 *
 * @param  array<string, mixed>  $object  the event's data.object
 */
function stripeWebhook(string $type, array $object, ?string $secret = null): TestResponse
{
    $payload = json_encode([
        'id' => 'evt_'.Str::random(14),
        'object' => 'event',
        'type' => $type,
        'data' => ['object' => $object],
    ]);
    $timestamp = time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret ?? config('cashier.webhook.secret'));

    return test()->call('POST', '/api/stripe/webhook', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
    ], content: $payload);
}

/**
 * A Stripe subscription object as webhooks send it (one item).
 *
 * @param  array<string, mixed>  $attributes
 * @return array<string, mixed>
 */
function stripeSubscription(Organization $organization, array $attributes = []): array
{
    return array_merge([
        'id' => 'sub_test',
        'object' => 'subscription',
        'customer' => $organization->stripe_id,
        'status' => 'active',
        'cancel_at_period_end' => false,
        'cancel_at' => null,
        'canceled_at' => null,
        'trial_end' => null,
        'metadata' => ['type' => 'default'],
        'items' => ['data' => [[
            'id' => 'si_test',
            'quantity' => 1,
            'price' => ['id' => config('billing.plans.pro.price'), 'product' => 'prod_test'],
        ]]],
    ], $attributes);
}
