<?php

namespace App\Http\Requests\Organizations;

use App\Http\Requests\Organizations\Concerns\ReferencesRole;
use Illuminate\Foundation\Http\FormRequest;

class StoreInvitationRequest extends FormRequest
{
    use ReferencesRole;

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
            'role_id' => $this->roleIdRules(),
        ];
    }
}
