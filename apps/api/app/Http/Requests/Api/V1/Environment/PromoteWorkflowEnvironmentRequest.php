<?php

namespace App\Http\Requests\Api\V1\Environment;

use Illuminate\Foundation\Http\FormRequest;

class PromoteWorkflowEnvironmentRequest extends FormRequest
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
            'from_environment_id' => ['required', 'integer', 'exists:workspace_environments,id'],
            'to_environment_id' => ['required', 'integer', 'exists:workspace_environments,id', 'different:from_environment_id'],
            'workflow_version_id' => ['nullable', 'integer', 'exists:workflow_versions,id'],
        ];
    }
}
