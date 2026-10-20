<?php

namespace App\Http\Requests\Organizations;

use App\Enums\OrganizationRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvitationRequest extends FormRequest
{
    // Permission depends on the role being granted; checked in the controller.
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
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', Rule::enum(OrganizationRole::class)],
        ];
    }
}
