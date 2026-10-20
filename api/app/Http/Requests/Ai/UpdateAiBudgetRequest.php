<?php

namespace App\Http\Requests\Ai;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAiBudgetRequest extends FormRequest
{
    // Permission is checked in the controller with AiUsagePolicy.
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Null goes back to the default; 0 turns AI off.
            'monthly_budget_usd' => ['present', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:10000'],
        ];
    }
}
