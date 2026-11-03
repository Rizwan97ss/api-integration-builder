<?php

namespace Rizwan\IntegrationBuilder\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Rizwan\IntegrationBuilder\Http\Requests\Concerns\IntegrationValidationRules;

class TestConnectionRequest extends FormRequest
{
    use IntegrationValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $testsEndpoint = $this->routeIs('integration-builder.test-endpoint');

        return [
            ...$this->connectionRules(),
            'auth.endpoint' => ['nullable', 'array'],
            ...($this->has('auth.endpoint') ? $this->endpointRules('auth.endpoint') : []),
            'endpoint' => [$testsEndpoint ? 'required' : 'nullable', 'array'],
            ...($testsEndpoint ? $this->endpointRules('endpoint') : []),
            'input' => ['nullable', 'array'],
            'input.path_params' => ['nullable', 'array'],
            'input.path_params.*' => ['nullable', 'string', 'max:500'],
            'input.query' => ['nullable', 'array'],
            'input.body' => ['nullable', 'array'],
            'input.headers' => ['nullable', 'array', 'max:20'],
            'input.headers.*.key' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'input.headers.*.value' => ['nullable', 'string', 'max:1000'],
            'input.skip_validation' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Get the "after" validation callables for the request.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (is_array($this->input('endpoint'))) {
                    $this->validateEndpoint($validator, $this->input('endpoint'), 'endpoint');
                }

                if (is_array($this->input('auth.endpoint'))) {
                    $this->validateEndpoint($validator, $this->input('auth.endpoint'), 'auth.endpoint');
                }
            },
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
            'endpoint.path.regex' => 'The endpoint path must be relative to the provider base URL (no scheme or host).',
        ];
    }
}
