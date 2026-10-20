<?php

namespace App\Http\Requests\Projects;

use App\Support\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectRequest extends FormRequest
{
    // Permission is checked in the controller with ProjectPolicy.
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
            'name' => [
                'required', 'string', 'max:255',
                // Unique within the current organization only (never an org id from the request).
                Rule::unique('projects', 'name')->where('organization_id', app(CurrentOrganization::class)->id()),
            ],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
