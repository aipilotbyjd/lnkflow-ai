<?php

namespace App\Http\Requests\Api\V1\AI;

use Illuminate\Foundation\Http\FormRequest;

class AnalyzeExecutionRequest extends FormRequest
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
            'auto_apply' => ['nullable', 'boolean'],
        ];
    }
}
