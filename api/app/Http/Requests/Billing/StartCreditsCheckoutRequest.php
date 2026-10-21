<?php

namespace App\Http\Requests\Billing;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartCreditsCheckoutRequest extends FormRequest
{
    // Checked here too (and again in the controller), so a role without the
    // permission gets 403 before validation.
    public function authorize(): bool
    {
        return $this->user()->can('manageBilling', Organization::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // A pack key from config/billing.php, never a price id or an amount.
            'pack' => ['required', 'string', Rule::in(array_keys(config('billing.credit_packs')))],
        ];
    }
}
