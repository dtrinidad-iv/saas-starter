<?php

namespace App\Http\Requests\Projects;

use App\Ai\Prompts\ProjectWritingPrompt;
use App\Enums\WritingHelpMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The project form's current text. Improving or shortening needs a description;
 * expanding works from the name alone.
 */
class ProjectWritingHelpRequest extends FormRequest
{
    // Permission is checked in the controller with ProjectPolicy::writeWithAi.
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
            'mode' => ['required', Rule::enum(WritingHelpMode::class)],
            'name' => ['nullable', 'string', 'max:255', 'required_without:description'],
            'description' => [
                'nullable', 'string', 'max:'.ProjectWritingPrompt::MAX_LENGTH,
                Rule::requiredIf(fn () => $this->input('mode') !== WritingHelpMode::Expand->value),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required_without' => 'Enter a name or a description first.',
            'description.required' => 'Write a description first.',
        ];
    }
}
