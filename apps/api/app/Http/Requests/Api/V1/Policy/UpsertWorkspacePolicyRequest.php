<?php

namespace App\Http\Requests\Api\V1\Policy;

use Illuminate\Foundation\Http\FormRequest;

class UpsertWorkspacePolicyRequest extends FormRequest
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
            'enabled' => ['required', 'boolean'],
            'allowed_node_types' => ['nullable', 'array'],
            'allowed_node_types.*' => ['string', 'max:100'],
            'blocked_node_types' => ['nullable', 'array'],
            'blocked_node_types.*' => ['string', 'max:100'],
            'allowed_ai_models' => ['nullable', 'array'],
            'allowed_ai_models.*' => ['string', 'max:120'],
            'blocked_ai_models' => ['nullable', 'array'],
            'blocked_ai_models.*' => ['string', 'max:120'],
            'max_execution_cost_usd' => ['nullable', 'numeric', 'min:0'],
            'max_ai_tokens' => ['nullable', 'integer', 'min:1'],
            'redaction_rules' => ['nullable', 'array'],
        ];
    }
}
