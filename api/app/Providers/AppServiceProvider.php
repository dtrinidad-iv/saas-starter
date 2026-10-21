<?php

namespace App\Providers;

use App\Ai\AiBudget;
use App\Ai\Clients\AnthropicClient;
use App\Ai\Clients\BudgetedAiClient;
use App\Ai\Clients\FakeAiClient;
use App\Ai\Clients\RecordingAiClient;
use App\Ai\Contracts\AiClient;
use App\Billing\Contracts\BillingGateway;
use App\Billing\FakeBillingGateway;
use App\Billing\StripeBillingGateway;
use App\Enums\Permission;
use App\Jobs\OrganizationJob;
use App\Models\Organization;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Laravel\Cashier\Cashier;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scoped: fresh per request and per queued job.
        $this->app->scoped(CurrentOrganization::class);

        // The provider from config/ai.php, wrapped so every call lands in ai_usage,
        // and refused up front once the organization's monthly budget is used up.
        $this->app->bind(AiClient::class, fn (Application $app) => new BudgetedAiClient(
            new RecordingAiClient(
                $app->make('ai.provider'),
                config('ai.model'),
                config('ai.pricing'),
            ),
            $app->make(AiBudget::class),
        ));

        $this->app->bind('ai.provider', fn (Application $app) => match (config('ai.provider')) {
            'anthropic' => new AnthropicClient(
                $app->make(HttpFactory::class),
                config('ai.providers.anthropic'),
                config('ai.model'),
                config('ai.timeout'),
                config('ai.max_tokens'),
                config('ai.fallbacks'),
                config('ai.fallback_models'),
                config('ai.stream_timeout'),
            ),
            // Tests bind their own instance (TestCase) to push responses and inspect requests.
            'fake' => $app->bound(FakeAiClient::class)
                ? $app->make(FakeAiClient::class)
                : new FakeAiClient(config('ai.model')),
            default => throw new InvalidArgumentException('Unknown AI provider ['.config('ai.provider').'].'),
        });

        // The organization is the Stripe customer. Cashier's own routes are off: our
        // webhook route (routes/api.php) always checks the signature.
        Cashier::useCustomerModel(Organization::class);
        Cashier::ignoreRoutes();
        // A failed renewal keeps the plan while Stripe retries the card; Stripe then
        // cancels or marks it unpaid (Billing settings), which ends the plan.
        Cashier::keepPastDueSubscriptionsActive();

        $this->app->bind(BillingGateway::class, fn (Application $app) => match (config('billing.provider')) {
            'stripe' => $app->make(StripeBillingGateway::class),
            // Tests bind their own instance (TestCase) to inspect what was started.
            'fake' => $app->bound(FakeBillingGateway::class)
                ? $app->make(FakeBillingGateway::class)
                : new FakeBillingGateway,
            default => throw new InvalidArgumentException('Unknown billing provider ['.config('billing.provider').'].'),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // A suspended user's tokens are deleted when they're suspended; this also
        // refuses one issued in a race with that (401, like a revoked token).
        Sanctum::authenticateAccessTokensUsing(
            fn (PersonalAccessToken $token, bool $isValid) => $isValid
                && ! ($token->tokenable instanceof User && $token->tokenable->isSuspended()),
        );

        // AI calls per organization: the route middleware (throttle:ai) counts requests
        // from the UI, the job middleware (RateLimited('ai')) counts calls to the
        // provider. Separate keys, so one click isn't counted twice.
        RateLimiter::for('ai', function (object $subject) {
            $limit = Limit::perMinute(config('ai.rate_limit_per_minute'));

            if ($subject instanceof OrganizationJob) {
                return $limit->by('job:'.$subject->organizationId);
            }

            // The organization middleware runs first; without an organization, limit per user.
            /** @var Request $subject */
            $organizationId = app(CurrentOrganization::class)->id();

            return $limit->by($organizationId !== null ? "request:{$organizationId}" : 'request:user:'.$subject->user()?->id)
                ->response(fn () => response()->json([
                    'message' => 'Too many AI requests for this organization. Try again in a minute.',
                ], 429));
        });

        // Billing routes that call Stripe. Stripe's rate limit is for the whole account,
        // so besides each user, an organization (however many owners it has) is capped too.
        // Only people who can manage billing count toward the organization's limit: the
        // throttle runs before the policy, and others' 403s must not lock the owners out.
        RateLimiter::for('stripe', function (Request $request) {
            $limits = [
                Limit::perMinute(20)->by('stripe:user:'.$request->user()?->id)->response(fn () => response()->json([
                    'message' => 'Too many billing requests. Try again in a minute.',
                ], 429)),
            ];

            $organization = app(CurrentOrganization::class);
            if ($organization->id() !== null && $organization->can(Permission::BillingManage)) {
                $limits[] = Limit::perMinute(60)->by('stripe:org:'.$organization->id())->response(fn () => response()->json([
                    'message' => 'Too many billing requests for this organization. Try again in a minute.',
                ], 429));
            }

            return $limits;
        });

        // Platform admin routes, per admin. The throttle runs before EnsurePlatformAdmin, so
        // non-admins aren't counted: a 429 would tell them the area exists (they get 404).
        RateLimiter::for('admin', function (Request $request) {
            $user = $request->user();

            return $user instanceof User && $user->isPlatformAdmin()
                ? Limit::perMinute(60)->by('admin:'.$user->id)
                : Limit::none();
        });
    }
}
