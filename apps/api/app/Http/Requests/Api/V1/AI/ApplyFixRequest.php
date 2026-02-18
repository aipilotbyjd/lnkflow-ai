<?php

namespace App\Http\Requests\Api\V1\AI;

use Illuminate\Foundation\Http\FormRequest;

class ApplyFixRequest extends FormRequest
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
            'suggestion_index' => ['required', 'integer', 'min:0'],
        ];
    }
}
