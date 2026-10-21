<?php

namespace App\Http\Requests\Ai;

use App\Ai\AiBudget;
use App\Models\AiUsage;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAiBudgetRequest extends FormRequest
{
    // Checked here too (and again in the controller), so a role without the
    // permission gets 403 before validation tells it the plan's limit.
    public function authorize(): bool
    {
        return $this->user()->can('manageBudget', AiUsage::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Null goes back to the plan's budget; 0 turns AI off. Never above the plan.
            'monthly_budget_usd' => ['present', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:'.$this->planLimit()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'monthly_budget_usd.max' => 'Your plan allows up to $'.number_format($this->planLimit(), 2).' a month. Upgrade the plan to raise it.',
        ];
    }

    private function planLimit(): float
    {
        return app(AiBudget::class)->planLimit(app(CurrentOrganization::class)->get());
    }
}
