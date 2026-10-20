<?php

namespace App\Providers;

use App\Ai\AiBudget;
use App\Ai\Clients\AnthropicClient;
use App\Ai\Clients\BudgetedAiClient;
use App\Ai\Clients\FakeAiClient;
use App\Ai\Clients\RecordingAiClient;
use App\Ai\Contracts\AiClient;
use App\Jobs\OrganizationJob;
use App\Support\CurrentOrganization;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
    }
}
