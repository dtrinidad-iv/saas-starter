<?php

namespace App\Http\Requests\Organizations;

use App\Http\Requests\Organizations\Concerns\ReferencesRole;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMemberRequest extends FormRequest
{
    use ReferencesRole;

    // Permission depends on the target member and new role; checked in the controller.
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
            'role_id' => $this->roleIdRules(),
        ];
    }
}
