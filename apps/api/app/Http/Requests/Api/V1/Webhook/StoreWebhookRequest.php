<?php

namespace App\Http\Requests\Api\V1\Webhook;

use App\Enums\WebhookAuthType;
use App\Enums\WebhookResponseMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreWebhookRequest extends FormRequest
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
            'workflow_id' => [
                'required',
                'integer',
                Rule::exists('workflows', 'id')
                    ->where('workspace_id', $this->route('workspace')->id),
                Rule::unique('webhooks', 'workflow_id'),
            ],
            'path' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9\-_]+$/i'],
            'methods' => ['nullable', 'array', 'min:1'],
            'methods.*' => ['string', Rule::in(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'])],
            'is_active' => ['nullable', 'boolean'],
            'auth_type' => ['nullable', new Enum(WebhookAuthType::class)],
            'auth_config' => ['nullable', 'array'],
            'auth_config.header_name' => ['required_if:auth_type,header', 'string', 'max:100'],
            'auth_config.header_value' => ['required_if:auth_type,header', 'string', 'max:500'],
            'auth_config.username' => ['required_if:auth_type,basic', 'string', 'max:100'],
            'auth_config.password' => ['required_if:auth_type,basic', 'string', 'max:500'],
            'auth_config.token' => ['required_if:auth_type,bearer', 'string', 'max:500'],
            'rate_limit' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'response_mode' => ['nullable', new Enum(WebhookResponseMode::class)],
            'response_status' => ['nullable', 'integer', 'min:100', 'max:599'],
            'response_body' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'workflow_id.unique' => 'This workflow already has a webhook configured.',
            'workflow_id.exists' => 'The selected workflow does not exist in this workspace.',
            'path.regex' => 'The path may only contain letters, numbers, dashes, and underscores.',
        ];
    }
}
