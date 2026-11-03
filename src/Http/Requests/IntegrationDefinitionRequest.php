<?php

namespace Rizwan\IntegrationBuilder\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Rizwan\IntegrationBuilder\Http\Requests\Concerns\IntegrationValidationRules;
use Rizwan\IntegrationBuilder\Services\FormPageTemplate;

class IntegrationDefinitionRequest extends FormRequest
{
    use IntegrationValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->connectionRules(),
            'name' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z][A-Za-z0-9 _\-]*$/'],
            'options' => ['nullable', 'array'],
            'options.timeout' => ['nullable', 'integer', 'min:1', 'max:120'],
            'options.retries' => ['nullable', 'integer', 'min:0', 'max:5'],
            'options.idempotency_header' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'options.frontend' => ['nullable', Rule::in(FormPageTemplate::FRONTENDS)],
            'endpoints' => ['required', 'array', 'min:1', 'max:100'],
            'endpoints.*' => ['array'],
            'endpoints.*.name' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z][A-Za-z0-9 _\-]*$/'],
            ...$this->endpointRules('endpoints.*'),
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
                $endpoints = $this->input('endpoints');

                if (! is_array($endpoints)) {
                    return;
                }

                $this->validateEndpointNames($validator, $endpoints);
                $this->validateControllerName($validator, (string) $this->input('name'));
                $this->validateStorageModels($validator, $endpoints, (string) $this->input('name'));
                $this->validateTokenProvider($validator, $endpoints, (string) $this->input('auth.type'), (string) $this->input('name'));

                foreach ($endpoints as $index => $endpoint) {
                    if (is_array($endpoint)) {
                        $this->validateEndpoint($validator, $endpoint, "endpoints.{$index}");
                    }
                }
            },
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'provider_id' => 'API provider',
            'endpoints' => 'endpoints',
            'endpoints.*.name' => 'endpoint name',
            'endpoints.*.path' => 'endpoint path',
            'endpoints.*.method' => 'HTTP method',
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
            'endpoints.*.path.regex' => 'The endpoint path must be relative to the provider base URL (no scheme or host).',
            'name.regex' => 'The integration name must start with a letter and contain only letters, numbers, spaces, dashes and underscores.',
            'endpoints.*.name.regex' => 'Endpoint names must start with a letter and contain only letters, numbers, spaces, dashes and underscores.',
        ];
    }
}
