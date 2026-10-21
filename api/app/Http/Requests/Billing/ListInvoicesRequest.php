<?php

namespace App\Http\Requests\Billing;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;

class ListInvoicesRequest extends FormRequest
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
            // A Stripe invoice id. The listing is always filtered by the organization's
            // own customer, so another organization's id can't reveal their invoices.
            'starting_after' => ['nullable', 'string', 'max:255', 'regex:/^in_[A-Za-z0-9_]+\z/'],
        ];
    }
}
