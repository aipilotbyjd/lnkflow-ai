<?php

namespace App\Http\Requests\Api\V1\Execution;

use Illuminate\Foundation\Http\FormRequest;

class RerunDeterministicRequest extends FormRequest
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
            'use_latest_workflow' => ['nullable', 'boolean'],
            'override_trigger_data' => ['nullable', 'array'],
        ];
    }
}
