<?php

namespace App\Actions\Ai;

use App\Actions\Audit\RecordAudit;
use App\Enums\AuditAction;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;

class UpdateAiBudget
{
    public function __construct(private readonly RecordAudit $recordAudit) {}

    /**
     * Set the organization's monthly AI budget in USD; null goes back to the default.
     */
    public function handle(Organization $organization, ?float $budget): Organization
    {
        return DB::transaction(function () use ($organization, $budget) {
            $old = $organization->ai_monthly_budget_usd;
            $organization->forceFill(['ai_monthly_budget_usd' => $budget])->save();
            $new = $organization->ai_monthly_budget_usd;

            if ($old !== $new) {
                $this->recordAudit->handle(
                    AuditAction::AiBudgetUpdated,
                    ['type' => 'organization', 'id' => $organization->id, 'label' => $organization->name],
                    changes: ['ai_monthly_budget_usd' => ['old' => $old, 'new' => $new]],
                );
            }

            return $organization;
        });
    }
}
