<?php

namespace DevForge\ApiKit\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

class GenerateIntegrationRequest extends IntegrationDefinitionRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'approved' => ['required', 'array', 'min:1'],
            'approved.*.path' => ['required', 'string', 'max:255', 'distinct'],
            'approved.*.hash' => ['required', 'string', 'size:40'],
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'approved.required' => 'Approve at least one file to generate.',
        ];
    }
}
