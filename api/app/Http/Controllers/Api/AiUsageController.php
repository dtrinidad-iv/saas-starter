<?php

namespace App\Http\Controllers\Api;

use App\Actions\Ai\AiUsageReport;
use App\Actions\Ai\UpdateAiBudget;
use App\Ai\AiBudget;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\AiUsageRequest;
use App\Http\Requests\Ai\UpdateAiBudgetRequest;
use App\Models\AiUsage;
use App\Support\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * AI usage and the monthly budget of the current organization.
 * CurrentOrganization is injected per action, not in the constructor: the router
 * reuses controller instances in long-lived processes (Octane, tests).
 */
class AiUsageController extends Controller
{
    public function index(AiUsageRequest $request, AiUsageReport $report, CurrentOrganization $currentOrganization): JsonResponse
    {
        Gate::authorize('viewAny', AiUsage::class);

        $data = $report->handle($currentOrganization->get(), $request->month());
        $data['budget']['can_manage'] = $request->user()->can('manageBudget', AiUsage::class);

        return response()->json(['data' => $data]);
    }

    public function updateBudget(UpdateAiBudgetRequest $request, UpdateAiBudget $action, AiBudget $budget, CurrentOrganization $currentOrganization): JsonResponse
    {
        Gate::authorize('manageBudget', AiUsage::class);

        $amount = $request->validated('monthly_budget_usd');
        $organization = $action->handle($currentOrganization->get(), $amount === null ? null : (float) $amount);

        return response()->json(['data' => [...$budget->summary($organization), 'can_manage' => true]]);
    }
}
