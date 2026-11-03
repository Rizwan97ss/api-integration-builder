<?php

namespace DevForge\ApiKit\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class InspectIntegrationRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z][A-Za-z0-9 _\-]*$/'],
        ];
    }
}
