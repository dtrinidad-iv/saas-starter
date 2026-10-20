<?php

namespace App\Http\Requests\Projects;

use App\Models\Project;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProjectRequest extends FormRequest
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
        /** @var Project $project */
        $project = $this->route('project');

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('projects', 'name')
                    ->where('organization_id', app(CurrentOrganization::class)->id())
                    ->ignore($project->id),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
