<?php

namespace DevForge\ApiDev\Http\Requests\Concerns;

use Closure;
use DevForge\ApiDev\Credentials\CredentialResolver;
use DevForge\ApiDev\Credentials\ProviderCredentials;
use DevForge\ApiDev\Services\CodeGenerator;
use DevForge\ApiDev\Services\IntegrationClient;
use DevForge\ApiDev\Services\SpecImporter;
use DevForge\ApiDev\Services\StorageSchema;
use DevForge\ApiDev\Services\ValidationRuleBuilder;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

trait IntegrationValidationRules
{
    /**
     * Maximum nesting depth of field definitions.
     */
    protected int $maxFieldDepth = 8;

    /**
     * Maximum number of fields per endpoint.
     */
    protected int $maxFieldCount = 300;

    /**
     * Method names that would clash with generated base classes.
     *
     * @var list<string>
     */
    protected array $reservedMethodNames = [
        'headers', 'getauthtoken', 'request', 'callaction', 'middleware', 'getmiddleware',
    ];

    /**
     * Get the rules for the provider and its authentication settings.
     *
     * @return array<string, array<int, ValidationRule|Closure|array<mixed>|string>|string>
     */
    protected function connectionRules(): array
    {
        $headerName = ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_\-]+$/'];
        $credentialColumn = ['nullable', Rule::in(ProviderCredentials::CREDENTIAL_KEYS)];

        return [
            'provider_id' => ['required', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_scalar($value) || app(CredentialResolver::class)->find((string) $value) === null) {
                    $fail('The selected API provider does not exist.');
                }
            }],
            'base_url_column' => ['nullable', Rule::in(ProviderCredentials::URL_KEYS)],
            'auth' => ['required', 'array'],
            'auth.type' => ['required', Rule::in(IntegrationClient::AUTH_TYPES)],
            'auth.prefix' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9_\-]*$/'],
            'auth.location' => ['nullable', Rule::in(['header', 'query'])],
            'auth.header_name' => $headerName,
            'auth.param_name' => $headerName,
            'auth.token_column' => $credentialColumn,
            'auth.key_column' => $credentialColumn,
            'auth.username_column' => $credentialColumn,
            'auth.password_column' => $credentialColumn,
            'auth.headers' => ['nullable', 'array', 'max:20'],
            'auth.headers.*.key' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'auth.headers.*.value' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Get the rules for an endpoint definition under the given key.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>|string>
     */
    protected function endpointRules(string $key): array
    {
        return [
            "{$key}.method" => ['required', Rule::in(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'])],
            "{$key}.path" => ['required', 'string', 'max:500', $this->relativePathRule()],
            "{$key}.description" => ['nullable', 'string', 'max:500'],
            "{$key}.body_format" => ['nullable', Rule::in(['json', 'form', 'multipart'])],
            "{$key}.store_response" => ['nullable', 'boolean'],
            "{$key}.requires_auth" => ['nullable', 'boolean'],
            "{$key}.create_form" => ['nullable', 'boolean'],
            "{$key}.provides_token" => ['nullable', 'boolean'],
            "{$key}.token_path" => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_\-]+(\.[A-Za-z0-9_\-]+)*$/'],
            "{$key}.expires_path" => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_\-]+(\.[A-Za-z0-9_\-]+)*$/'],
            "{$key}.token_ttl" => ['nullable', 'integer', 'min:60', 'max:2592000'],
            "{$key}.basic_username" => ['nullable', Rule::in(ProviderCredentials::CREDENTIAL_KEYS)],
            "{$key}.basic_password" => ['nullable', 'required_with:'."{$key}.basic_username", Rule::in(ProviderCredentials::CREDENTIAL_KEYS)],
            "{$key}.base_url_column" => ['nullable', Rule::in(ProviderCredentials::URL_KEYS)],
            "{$key}.model" => ['nullable', 'string', 'max:60', 'regex:/^[A-Za-z][A-Za-z0-9]*$/'],
            "{$key}.headers" => ['nullable', 'array', 'max:20'],
            "{$key}.headers.*.key" => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_\-]+$/'],
            "{$key}.headers.*.value" => ['nullable', 'string', 'max:1000'],
            "{$key}.query" => ['nullable', 'array'],
            "{$key}.body" => ['nullable', 'array'],
        ];
    }

    /**
     * Get the rule that keeps endpoint paths relative to the provider URL.
     *
     * Absolute URLs are rejected so stored credentials can never be sent to
     * a host other than the one configured on the provider.
     */
    protected function relativePathRule(): string
    {
        return 'regex:#^(?!.*://)(?!//)[^\s]*$#';
    }

    /**
     * Validate an endpoint's path parameters and field trees.
     *
     * @param  array<string, mixed>  $endpoint
     */
    protected function validateEndpoint(Validator $validator, array $endpoint, string $key): void
    {
        preg_match_all('/\{([^}]*)\}/', (string) ($endpoint['path'] ?? ''), $parameters);

        foreach ($parameters[1] as $parameter) {
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $parameter)) {
                $validator->errors()->add("{$key}.path", "Path parameter \"{$parameter}\" must be a valid identifier.");
            }
        }

        $count = 0;

        foreach (['query', 'body'] as $section) {
            if (is_array($endpoint[$section] ?? null)) {
                $this->validateFieldTree($validator, $endpoint[$section], "{$key}.{$section}", 0, $count);
            }
        }

        if ($endpoint['provides_token'] ?? false) {
            $this->validateTokenEndpoint($validator, $endpoint, $key);
        }

        $queryNames = array_map(strtolower(...), array_column((array) ($endpoint['query'] ?? []), 'name'));
        $bodyNames = array_map(strtolower(...), array_column((array) ($endpoint['body'] ?? []), 'name'));

        foreach (array_intersect($queryNames, $bodyNames) as $duplicate) {
            $validator->errors()->add("{$key}.query", "\"{$duplicate}\" is used as both a query and a body field.");
        }
    }

    /**
     * Recursively validate a list of field definitions.
     *
     * @param  array<array-key, mixed>  $fields
     */
    protected function validateFieldTree(Validator $validator, array $fields, string $key, int $depth, int &$count, bool $insideArray = false): void
    {
        if ($depth > $this->maxFieldDepth) {
            $validator->errors()->add($key, "Fields may be nested at most {$this->maxFieldDepth} levels deep.");

            return;
        }

        $names = [];

        foreach ($fields as $index => $field) {
            $path = "{$key}.{$index}";

            if (! is_array($field)) {
                $validator->errors()->add($path, 'Invalid field definition.');

                continue;
            }

            if (++$count > $this->maxFieldCount) {
                $validator->errors()->add($key, "An endpoint may define at most {$this->maxFieldCount} fields.");

                return;
            }

            $name = $field['name'] ?? null;

            if (! is_string($name) || strlen($name) > 100 || ! preg_match(SpecImporter::FIELD_NAME_PATTERN, $name)) {
                $validator->errors()->add("{$path}.name", 'Use letters, numbers, dashes and underscores, starting with a letter or underscore.');
            } elseif (in_array(strtolower($name), $names, true)) {
                $validator->errors()->add("{$path}.name", "Duplicate field name \"{$name}\".");
            } else {
                $names[] = strtolower($name);
            }

            $type = $field['type'] ?? null;

            if (! is_string($type) || ! array_key_exists($type, ValidationRuleBuilder::TYPE_RULES)) {
                $validator->errors()->add("{$path}.type", 'Choose a supported field type.');
            }

            foreach (['required', 'nullable'] as $flag) {
                if (isset($field[$flag]) && ! is_bool($field[$flag])) {
                    $validator->errors()->add("{$path}.{$flag}", 'Must be true or false.');
                }
            }

            $this->validateExtraRules($validator, $field['rules'] ?? null, "{$path}.rules");

            if (isset($field['enum']) && (! is_string($field['enum']) || strlen($field['enum']) > 1000)) {
                $validator->errors()->add("{$path}.enum", 'Enter allowed values separated by commas.');
            }

            if (filled($field['item_type'] ?? null) && ! in_array($field['item_type'], ValidationRuleBuilder::SCALAR_TYPES, true)) {
                $validator->errors()->add("{$path}.item_type", 'Choose a scalar item type.');
            }

            $this->validateFieldSource($validator, $field, $path, $insideArray);

            if (isset($field['children']) && is_array($field['children']) && in_array($type, ['object', 'array'], true)) {
                $this->validateFieldTree($validator, $field['children'], "{$path}.children", $depth + 1, $count, $insideArray || $type === 'array');
            }
        }

        foreach ($fields as $index => $field) {
            if (is_array($field) && is_array($field['condition'] ?? null) && filled($field['condition']['field'] ?? null)) {
                $this->validateCondition($validator, $field, $names, "{$key}.{$index}.condition");
            }
        }
    }

    /**
     * Validate where a field's value comes from.
     *
     * @param  array<string, mixed>  $field
     */
    protected function validateFieldSource(Validator $validator, array $field, string $path, bool $insideArray): void
    {
        $source = $field['source'] ?? 'input';

        if (! in_array($source, ValidationRuleBuilder::FIELD_SOURCES, true)) {
            $validator->errors()->add("{$path}.source", 'Choose where the value comes from.');

            return;
        }

        if ($source === 'input') {
            return;
        }

        if ($insideArray || in_array($field['type'] ?? null, ['object', 'array', 'file'], true)) {
            $validator->errors()->add("{$path}.source", 'Only single values outside lists can come from a credential or a fixed value.');
        }

        if ($source === 'credential' && ! in_array($field['credential'] ?? null, ProviderCredentials::CREDENTIAL_KEYS, true)) {
            $validator->errors()->add("{$path}.credential", 'Choose the credential column that holds the value.');
        }

        if ($source === 'fixed' && (! is_scalar($field['value'] ?? null) || strlen((string) $field['value']) > 1000)) {
            $validator->errors()->add("{$path}.value", 'Enter the fixed value to send.');
        }
    }

    /**
     * Validate an endpoint marked as providing the auth token.
     *
     * The token is fetched automatically, so every field must come from a
     * credential column or a fixed value and the path cannot take parameters.
     *
     * @param  array<string, mixed>  $endpoint
     */
    protected function validateTokenEndpoint(Validator $validator, array $endpoint, string $key): void
    {
        $rules = new ValidationRuleBuilder;

        if ($rules->inputFields([...(array) ($endpoint['query'] ?? []), ...(array) ($endpoint['body'] ?? [])]) !== []) {
            $validator->errors()->add("{$key}.provides_token", 'Every field on the token endpoint needs a credential or fixed value, because the token is fetched automatically.');
        }

        if (str_contains((string) ($endpoint['path'] ?? ''), '{')) {
            $validator->errors()->add("{$key}.path", 'The token endpoint path cannot contain parameters.');
        }
    }

    /**
     * Ensure the endpoints and the auth type agree on where the token comes from.
     *
     * @param  array<array-key, mixed>  $endpoints
     */
    protected function validateTokenProvider(Validator $validator, array $endpoints, string $authType, string $integration): void
    {
        $providers = array_keys(array_filter($endpoints, fn (mixed $endpoint): bool => is_array($endpoint) && (bool) ($endpoint['provides_token'] ?? false)));
        $usesTokenEndpoint = in_array($authType, IntegrationClient::TOKEN_AUTH_TYPES, true);

        foreach ($providers as $position => $index) {
            if (! $usesTokenEndpoint) {
                $validator->errors()->add("endpoints.{$index}.provides_token", 'Choose "Token from an endpoint" as the authentication method to use this endpoint\'s token.');
            } elseif ($position > 0) {
                $validator->errors()->add("endpoints.{$index}.provides_token", 'Only one endpoint can provide the token.');
            }
        }

        if ($usesTokenEndpoint && $providers === [] && ! app(CodeGenerator::class)->inspect($integration)['exists']) {
            $validator->errors()->add('endpoints', 'Add the endpoint that returns the token (for example POST /oauth/token) and tick "This endpoint provides the token".');
        }
    }

    /**
     * Validate a field's condition against its sibling fields.
     *
     * @param  array<string, mixed>  $field
     * @param  list<string>  $siblings
     */
    protected function validateCondition(Validator $validator, array $field, array $siblings, string $key): void
    {
        $condition = $field['condition'];
        $target = strtolower((string) $condition['field']);

        if (! in_array($target, $siblings, true) || $target === strtolower((string) ($field['name'] ?? ''))) {
            $validator->errors()->add("{$key}.field", 'Conditions must reference another field at the same level.');
        }

        if (! in_array($condition['operator'] ?? 'equals', ValidationRuleBuilder::CONDITION_OPERATORS, true)) {
            $validator->errors()->add("{$key}.operator", 'Choose a supported condition.');
        }

        $value = $condition['value'] ?? '';

        if (! is_scalar($value) || str_contains((string) $value, ',') || strlen((string) $value) > 100) {
            $validator->errors()->add("{$key}.value", 'Condition values may not contain commas.');
        }
    }

    /**
     * Validate pipe separated extra rules against Laravel's built-in rules.
     */
    protected function validateExtraRules(Validator $validator, mixed $rules, string $key): void
    {
        if ($rules === null || $rules === '') {
            return;
        }

        if (! is_string($rules) || strlen($rules) > 500) {
            $validator->errors()->add($key, 'Enter rules separated by "|".');

            return;
        }

        foreach ((new ValidationRuleBuilder)->extraRules($rules) as $rule) {
            $name = Str::before($rule, ':');

            if (! preg_match('/^[a-z_]+$/', $name) || ! method_exists(Validator::class, 'validate'.Str::studly($name))) {
                $validator->errors()->add($key, "\"{$name}\" is not a Laravel validation rule.");
            }
        }
    }

    /**
     * Ensure new methods can be added to an existing controller.
     */
    protected function validateControllerName(Validator $validator, string $integration): void
    {
        if (! preg_match('/^[A-Za-z][A-Za-z0-9 _\-]*$/', $integration)) {
            return;
        }

        $controller = app(CodeGenerator::class)->inspect($integration);

        if (! $controller['compatible']) {
            $validator->errors()->add(
                'name',
                "{$controller['controller']} already exists but has no getAuthToken() and request() methods, so new methods cannot be added to it.",
            );
        }
    }

    /**
     * Ensure an existing storage model accepts every column the controller saves.
     *
     * @param  array<array-key, mixed>  $endpoints
     */
    protected function validateStorageModels(Validator $validator, array $endpoints, string $integration): void
    {
        $studly = Str::studly((string) preg_replace('/[^A-Za-z0-9]+/', ' ', $integration));

        foreach ($endpoints as $index => $endpoint) {
            if (! is_array($endpoint) || ! ($endpoint['store_response'] ?? false)) {
                continue;
            }

            $model = StorageSchema::modelName($endpoint, $studly);
            $path = base_path("app/Models/{$model}.php");

            if (! File::exists($path)) {
                continue;
            }

            $code = File::get($path);
            $body = (new ValidationRuleBuilder)->inputFields((array) ($endpoint['body'] ?? []));
            $columns = [...array_column((new StorageSchema)->columns($body), 'column'), 'external_id', 'response'];
            $missing = array_values(array_filter($columns, fn (string $column): bool => ! str_contains($code, "'{$column}'")));

            if ($missing !== []) {
                $validator->errors()->add(
                    "endpoints.{$index}.model",
                    'App\\Models\\'.$model.' already exists but its $fillable is missing: '.implode(', ', $missing).'. Add them or choose another model name.',
                );
            }
        }
    }

    /**
     * Validate that endpoint method names are usable and unique.
     *
     * @param  array<array-key, mixed>  $endpoints
     */
    protected function validateEndpointNames(Validator $validator, array $endpoints): void
    {
        $methods = [];

        foreach ($endpoints as $index => $endpoint) {
            $method = Str::camel((string) preg_replace('/[^A-Za-z0-9]+/', ' ', (string) ($endpoint['name'] ?? '')));
            $lower = strtolower($method);

            if (in_array($lower, $this->reservedMethodNames, true) || str_starts_with($method, '__')) {
                $validator->errors()->add("endpoints.{$index}.name", "\"{$method}\" is reserved; choose another name.");
            } elseif (in_array($lower, $methods, true)) {
                $validator->errors()->add("endpoints.{$index}.name", "Another endpoint already generates the method \"{$method}\".");
            }

            $methods[] = $lower;
        }
    }

    /**
     * Get the provider selected in the request.
     */
    public function provider(): ProviderCredentials
    {
        return app(CredentialResolver::class)->find((string) $this->input('provider_id')) ?? abort(404);
    }
}
