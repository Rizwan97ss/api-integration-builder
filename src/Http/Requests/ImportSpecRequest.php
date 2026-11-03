<?php

namespace DevForge\IntegrationBuilder\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImportSpecRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $isSample = $this->input('source') === 'sample_json';

        return [
            'source' => ['required', Rule::in(['sample_json', 'openapi'])],
            'content' => ['required', 'string', 'max:2000000'],
            'method' => [$isSample ? 'required' : 'nullable', Rule::in(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'])],
            'path' => [$isSample ? 'required' : 'nullable', 'string', 'max:500', 'regex:#^(?!.*://)(?!//)[^\s]*$#'],
            'name' => ['nullable', 'string', 'max:60', 'regex:/^[A-Za-z][A-Za-z0-9 _\-]*$/'],
            'target' => ['nullable', Rule::in(['body', 'query'])],
            'mark_required' => ['nullable', 'boolean'],
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
            'path.regex' => 'The endpoint path must be relative to the provider base URL (no scheme or host).',
            'content.max' => 'The document may not be larger than 2 MB.',
        ];
    }
}
