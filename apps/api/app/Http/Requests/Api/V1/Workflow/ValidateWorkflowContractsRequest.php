<?php

namespace App\Http\Requests\Api\V1\Workflow;

use Illuminate\Foundation\Http\FormRequest;

class ValidateWorkflowContractsRequest extends FormRequest
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
            'nodes' => ['nullable', 'array', 'min:1'],
            'nodes.*.id' => ['required_with:nodes', 'string', 'max:100'],
            'nodes.*.type' => ['required_with:nodes', 'string', 'max:100'],
            'nodes.*.data' => ['nullable', 'array'],

            'edges' => ['nullable', 'array'],
            'edges.*.id' => ['required_with:edges', 'string', 'max:100'],
            'edges.*.source' => ['required_with:edges', 'string', 'max:100'],
            'edges.*.target' => ['required_with:edges', 'string', 'max:100'],

            'strict' => ['nullable', 'boolean'],
        ];
    }
}
