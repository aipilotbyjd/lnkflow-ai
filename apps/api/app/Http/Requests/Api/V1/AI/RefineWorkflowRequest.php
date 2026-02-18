<?php

namespace App\Http\Requests\Api\V1\AI;

use Illuminate\Foundation\Http\FormRequest;

class RefineWorkflowRequest extends FormRequest
{
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
            'generation_log_id' => ['required', 'integer', 'exists:ai_generation_logs,id'],
            'feedback' => ['required', 'string', 'min:5', 'max:2000'],
        ];
    }
}
