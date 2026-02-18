<?php

namespace App\Http\Requests\Api\V1\AI;

use Illuminate\Foundation\Http\FormRequest;

class GenerateWorkflowRequest extends FormRequest
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
            'prompt' => ['required', 'string', 'min:10', 'max:2000'],
            'credential_ids' => ['nullable', 'array'],
            'credential_ids.*' => ['integer', 'exists:credentials,id'],
            'options' => ['nullable', 'array'],
            'options.dry_run' => ['nullable', 'boolean'],
        ];
    }
}
