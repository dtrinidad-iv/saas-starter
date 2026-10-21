<?php

namespace App\Actions\Billing;

use App\Actions\Audit\RecordAudit;
use App\Billing\Plans;
use App\Enums\AuditAction;
use App\Models\Organization;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Wraps a webhook's subscription update: if the organization's plan as Stripe
 * reports it is different afterwards, records it in the audit log. Comparing plans
 * (not events) keeps it idempotent: Stripe retries and events that change nothing
 * record nothing. The organization row is locked so its webhooks run one at a time.
 */
class TrackPlanChange
{
    public function __construct(
        private readonly Plans $plans,
        private readonly RecordAudit $recordAudit,
    ) {}

    /**
     * @template T
     *
     * @param  Closure(): T  $update
     * @return T
     */
    public function handle(?Organization $organization, Closure $update): mixed
    {
        if ($organization === null) {
            return $update();
        }

        return DB::transaction(function () use ($organization, $update) {
            Organization::query()->lockForUpdate()->find($organization->id);

            $before = $this->plans->reportedFor($organization->load('subscriptions'));
            $result = $update();
            $after = $this->plans->reportedFor($organization->load('subscriptions'));

            if ($this->plans->paidSubscriptions($organization)->count() > 1) {
                Log::warning('Organization has more than one paid plan subscription; refund the extra one in Stripe.', [
                    'organization_id' => $organization->id,
                    'subscriptions' => $this->plans->paidSubscriptions($organization)->pluck('stripe_id')->all(),
                ]);
            }

            if ($before->key !== $after->key) {
                $this->recordAudit->handle(
                    AuditAction::PlanChanged,
                    ['type' => 'organization', 'id' => $organization->id, 'label' => $organization->name],
                    changes: ['plan' => ['old' => $before->key, 'new' => $after->key]],
                    organization: $organization,
                );
            }

            return $result;
        });
    }
}
