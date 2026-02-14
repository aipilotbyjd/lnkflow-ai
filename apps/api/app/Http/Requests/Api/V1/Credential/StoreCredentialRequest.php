<?php

namespace App\Http\Requests\Api\V1\Credential;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCredentialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('credentials')
                    ->where('workspace_id', $this->route('workspace')->id)
                    ->whereNull('deleted_at'),
            ],
            'type' => [
                'required',
                'string',
                Rule::exists('credential_types', 'type'),
            ],
            'data' => ['required', 'array'],
            'data.*' => ['nullable'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'A credential with this name already exists in this workspace.',
            'type.exists' => 'The selected credential type is invalid.',
        ];
    }
}
