<?php

namespace App\Http\Requests\AuditLog;

use App\Enums\AuditAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexAuditLogRequest extends FormRequest
{
    // Permission is checked in the controller with AuditLogPolicy.
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
            'action' => ['nullable', Rule::enum(AuditAction::class)],
            // Only filters entries of the current organization, so any id is safe.
            'actor_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
