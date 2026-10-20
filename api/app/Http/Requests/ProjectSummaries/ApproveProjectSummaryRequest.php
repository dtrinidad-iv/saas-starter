<?php

namespace App\Http\Requests\ProjectSummaries;

use App\Ai\Prompts\ProjectSummaryPrompt;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Approving may also edit the draft. Edits get the same limits as the model's output.
 */
class ApproveProjectSummaryRequest extends FormRequest
{
    // Permission is checked in the controller with ProjectSummaryPolicy.
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = app(ProjectSummaryPrompt::class)->rules();

        // Both fields are optional here: leaving one out keeps the draft's value.
        foreach (['summary', 'key_points'] as $field) {
            $rules[$field] = ['sometimes', ...array_values(array_diff($rules[$field], ['present']))];
        }

        return $rules;
    }
}
