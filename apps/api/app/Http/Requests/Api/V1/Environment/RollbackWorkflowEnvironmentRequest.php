<?php

namespace App\Http\Requests\Api\V1\Environment;

use Illuminate\Foundation\Http\FormRequest;

class RollbackWorkflowEnvironmentRequest extends FormRequest
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
            'to_environment_id' => ['required', 'integer', 'exists:workspace_environments,id'],
            'workflow_version_id' => ['required', 'integer', 'exists:workflow_versions,id'],
        ];
    }
}
