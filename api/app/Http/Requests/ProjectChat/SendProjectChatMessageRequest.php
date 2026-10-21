<?php

namespace App\Http\Requests\ProjectChat;

use App\Ai\Prompts\ProjectChatPrompt;
use Illuminate\Foundation\Http\FormRequest;

class SendProjectChatMessageRequest extends FormRequest
{
    // Permission is checked in the controller with ProjectPolicy::chat.
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
            'message' => ['required', 'string', 'max:'.ProjectChatPrompt::MAX_MESSAGE_LENGTH],
        ];
    }
}
