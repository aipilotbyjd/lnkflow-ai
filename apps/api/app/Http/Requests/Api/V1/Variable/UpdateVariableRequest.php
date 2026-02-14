<?php

namespace App\Http\Requests\Api\V1\Variable;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVariableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Handled by controller
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'key' => [
                'sometimes',
                'string',
                'max:100',
                'regex:/^[A-Z][A-Z0-9_]*$/',
                Rule::unique('variables', 'key')
                    ->where('workspace_id', $this->route('workspace')->id)
                    ->ignore($this->route('variable')->id),
            ],
            'value' => ['sometimes', 'string'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_secret' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'key.regex' => 'The key must start with an uppercase letter and contain only uppercase letters, numbers, and underscores.',
        ];
    }
}
