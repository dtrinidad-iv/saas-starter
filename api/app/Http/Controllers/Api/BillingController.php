<?php

namespace App\Http\Controllers\Api;

use App\Actions\Billing\BillingOverview;
use App\Actions\Billing\OpenBillingPortal;
use App\Actions\Billing\StartCreditsCheckout;
use App\Actions\Billing\StartSubscriptionCheckout;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\StartCheckoutRequest;
use App\Http\Requests\Billing\StartCreditsCheckoutRequest;
use App\Models\Organization;
use App\Support\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Billing of the current organization. Payments happen on Stripe's hosted pages;
 * these endpoints only return their URLs.
 */
class BillingController extends Controller
{
    public function show(BillingOverview $overview, CurrentOrganization $currentOrganization): JsonResponse
    {
        Gate::authorize('manageBilling', Organization::class);

        return response()->json(['data' => $overview->handle($currentOrganization->get())]);
    }

    public function checkout(StartCheckoutRequest $request, StartSubscriptionCheckout $action, CurrentOrganization $currentOrganization): JsonResponse
    {
        Gate::authorize('manageBilling', Organization::class);

        $url = $action->handle($currentOrganization->get(), $request->user(), $request->validated('plan'));

        return response()->json(['data' => ['url' => $url]]);
    }

    public function creditsCheckout(StartCreditsCheckoutRequest $request, StartCreditsCheckout $action, CurrentOrganization $currentOrganization): JsonResponse
    {
        Gate::authorize('manageBilling', Organization::class);

        $url = $action->handle($currentOrganization->get(), $request->user(), $request->validated('pack'));

        return response()->json(['data' => ['url' => $url]]);
    }

    public function portal(OpenBillingPortal $action, CurrentOrganization $currentOrganization): JsonResponse
    {
        Gate::authorize('manageBilling', Organization::class);

        return response()->json(['data' => ['url' => $action->handle($currentOrganization->get())]]);
    }
}
