<?php

namespace App\Http\Requests\Api\V1\Workflow;

use App\Enums\TriggerType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWorkflowRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'icon' => ['nullable', 'string', 'max:50'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],

            'trigger_type' => ['required', Rule::enum(TriggerType::class)],
            'trigger_config' => ['nullable', 'array'],
            'trigger_config.cron' => ['required_if:trigger_type,schedule', 'nullable', 'string'],
            'trigger_config.timezone' => ['nullable', 'string', 'timezone'],
            'trigger_config.path' => ['required_if:trigger_type,webhook', 'nullable', 'string', 'max:100', 'regex:/^[a-z0-9\-_]+$/i'],
            'trigger_config.method' => ['nullable', 'array'],
            'trigger_config.method.*' => ['string', Rule::in(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'])],

            'nodes' => ['required', 'array', 'min:1'],
            'nodes.*.id' => ['required', 'string', 'max:100'],
            'nodes.*.type' => ['required', 'string', 'max:100'],
            'nodes.*.position' => ['required', 'array'],
            'nodes.*.position.x' => ['required', 'numeric'],
            'nodes.*.position.y' => ['required', 'numeric'],
            'nodes.*.data' => ['required', 'array'],

            'edges' => ['present', 'array'],
            'edges.*.id' => ['required', 'string', 'max:100'],
            'edges.*.source' => ['required', 'string', 'max:100'],
            'edges.*.target' => ['required', 'string', 'max:100'],
            'edges.*.sourceHandle' => ['nullable', 'string', 'max:100'],
            'edges.*.targetHandle' => ['nullable', 'string', 'max:100'],
            'edges.*.type' => ['nullable', 'string', 'max:50'],

            'viewport' => ['nullable', 'array'],
            'viewport.x' => ['nullable', 'numeric'],
            'viewport.y' => ['nullable', 'numeric'],
            'viewport.zoom' => ['nullable', 'numeric', 'min:0.1', 'max:10'],

            'settings' => ['nullable', 'array'],
            'settings.retry' => ['nullable', 'array'],
            'settings.retry.enabled' => ['nullable', 'boolean'],
            'settings.retry.max_attempts' => ['nullable', 'integer', 'min:1', 'max:10'],
            'settings.retry.delay_seconds' => ['nullable', 'integer', 'min:1', 'max:3600'],
            'settings.timeout' => ['nullable', 'array'],
            'settings.timeout.workflow' => ['nullable', 'integer', 'min:60', 'max:86400'],
            'settings.timeout.node' => ['nullable', 'integer', 'min:10', 'max:3600'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'trigger_config.cron.required_if' => 'The cron expression is required for scheduled workflows.',
            'trigger_config.path.required_if' => 'The webhook path is required for webhook-triggered workflows.',
            'trigger_config.path.regex' => 'The webhook path may only contain letters, numbers, dashes, and underscores.',
            'nodes.min' => 'A workflow must have at least one node.',
        ];
    }
}
