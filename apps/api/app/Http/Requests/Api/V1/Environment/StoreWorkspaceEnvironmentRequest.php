<?php

namespace App\Http\Requests\Api\V1\Environment;

use Illuminate\Foundation\Http\FormRequest;

class StoreWorkspaceEnvironmentRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:100'],
            'git_branch' => ['required', 'string', 'max:160'],
            'base_branch' => ['nullable', 'string', 'max:160'],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
