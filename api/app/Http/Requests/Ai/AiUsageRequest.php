<?php

namespace App\Http\Requests\Ai;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

class AiUsageRequest extends FormRequest
{
    // Permission is checked in the controller with AiUsagePolicy.
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
            'month' => ['nullable', 'date_format:Y-m'],
        ];
    }

    /**
     * The requested month in UTC, or the current one.
     */
    public function month(): CarbonImmutable
    {
        $month = $this->validated('month');

        return $month !== null
            ? CarbonImmutable::createFromFormat('Y-m-d', $month.'-01', 'UTC')->startOfMonth()
            : CarbonImmutable::now('UTC')->startOfMonth();
    }
}
