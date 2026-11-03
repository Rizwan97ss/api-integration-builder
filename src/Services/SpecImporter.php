<?php

namespace DevForge\ApiDev\Services;

use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Detects endpoints and fields from sample JSON or OpenAPI / Swagger documents.
 */
class SpecImporter
{
    /**
     * Maximum depth for nested schemas and `$ref` resolution.
     */
    protected const int MAX_DEPTH = 6;

    /**
     * Maximum number of endpoints imported from a single document.
     */
    protected const int MAX_ENDPOINTS = 100;

    /**
     * Sample values that only name a type, such as "string" in API docs.
     *
     * @var list<string>
     */
    protected const array PLACEHOLDER_VALUES = ['string', 'integer', 'number', 'boolean', 'int', 'float', 'date', 'datetime', 'uuid', 'email', 'url', ''];

    /**
     * Whether fields inferred from sample JSON are marked as required.
     */
    protected bool $markRequired = true;

    /**
     * Allowed field name pattern, matching the builder's validation.
     */
    public const string FIELD_NAME_PATTERN = '/^[A-Za-z_][A-Za-z0-9_\-]*$/';

    /**
     * The OpenAPI document being imported.
     *
     * @var array<string, mixed>
     */
    protected array $document = [];

    /**
     * Warnings collected while importing.
     *
     * @var list<string>
     */
    protected array $warnings = [];

    /**
     * Build a single endpoint from a sample JSON request body.
     *
     * Fields go to the query string for GET requests and to the body
     * otherwise, unless a target is given.
     *
     * @param  'body'|'query'|null  $target
     * @return array{title: string|null, endpoints: list<array<string, mixed>>, suggested_auth: array<string, mixed>|null, servers: list<string>, warnings: list<string>}
     */
    public function fromSampleJson(string $json, string $method, string $path, ?string $name = null, ?string $target = null, bool $markRequired = true): array
    {
        $this->warnings = [];
        $this->markRequired = $markRequired;

        $data = $this->decodeJson($json);

        if (is_array($data) && array_is_list($data)) {
            $this->warnings[] = 'The sample is a JSON array; fields were inferred from its first item.';
            $data = is_array($data[0] ?? null) ? $data[0] : [];
        }

        if (! is_array($data)) {
            throw new InvalidArgumentException('The sample must be a JSON object.');
        }

        $method = strtoupper($method);
        $fields = $this->inferFields($data);

        $endpoint = $this->endpoint(
            name: filled($name) ? (string) $name : $this->defaultName($method, $path),
            method: $method,
            path: $path,
        );

        $endpoint[$target ?? ($method === 'GET' ? 'query' : 'body')] = $fields;

        return [
            'title' => null,
            'endpoints' => [$endpoint],
            'suggested_auth' => null,
            'servers' => [],
            'warnings' => $this->warnings,
        ];
    }

    /**
     * Import every supported operation from an OpenAPI 3 or Swagger 2 document.
     *
     * @return array{title: string|null, endpoints: list<array<string, mixed>>, suggested_auth: array<string, mixed>|null, servers: list<string>, warnings: list<string>}
     */
    public function fromOpenApi(string $content): array
    {
        $this->warnings = [];
        $this->document = $this->decodeDocument($content);

        if (! isset($this->document['openapi']) && ! isset($this->document['swagger'])) {
            throw new InvalidArgumentException('The document has no "openapi" or "swagger" version key.');
        }

        $endpoints = [];
        $names = [];

        foreach ((array) ($this->document['paths'] ?? []) as $path => $item) {
            $item = $this->resolve((array) $item);

            foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
                if (! isset($item[$method]) || ! is_array($item[$method])) {
                    continue;
                }

                if (count($endpoints) >= self::MAX_ENDPOINTS) {
                    $this->warnings[] = sprintf('Only the first %d operations were imported.', self::MAX_ENDPOINTS);

                    break 2;
                }

                $endpoint = $this->operation((string) $path, $method, $item[$method], (array) ($item['parameters'] ?? []));
                $endpoint['name'] = $this->uniqueName($endpoint['name'], $names);
                $names[] = Str::lower($endpoint['name']);

                $endpoints[] = $endpoint;
            }
        }

        if ($endpoints === []) {
            $this->warnings[] = 'No GET, POST, PUT, PATCH or DELETE operations were found.';
        }

        return [
            'title' => isset($this->document['info']['title']) ? (string) $this->document['info']['title'] : null,
            'endpoints' => $endpoints,
            'suggested_auth' => $this->suggestedAuth(),
            'servers' => $this->servers(),
            'warnings' => array_values(array_unique($this->warnings)),
        ];
    }

    /**
     * Infer field definitions from a decoded JSON object.
     *
     * @param  array<array-key, mixed>  $data
     * @return list<array<string, mixed>>
     */
    public function inferFields(array $data, int $depth = 0): array
    {
        $fields = [];

        foreach ($data as $name => $value) {
            $name = (string) $name;

            if (! preg_match(self::FIELD_NAME_PATTERN, $name)) {
                $this->warnings[] = "Skipped field \"{$name}\": names may only contain letters, numbers, dashes and underscores.";

                continue;
            }

            $fields[] = $this->inferField($name, $value, $depth);
        }

        return $fields;
    }

    /**
     * Infer a single field definition from a sample value.
     *
     * @return array<string, mixed>
     */
    protected function inferField(string $name, mixed $value, int $depth): array
    {
        $field = $this->field($name, $this->inferScalarType($value), $this->markRequired && $value !== null);
        $field['nullable'] = $value === null;
        $field['example'] = is_scalar($value) ? (string) (is_bool($value) ? ($value ? 'true' : 'false') : $value) : null;

        if (is_string($value) && in_array(Str::lower($value), self::PLACEHOLDER_VALUES, true)) {
            $field['example'] = null;
        }

        if (is_string($value) && preg_match('/^[A-Z][A-Z0-9]*(?:_[A-Z0-9]+)*$/', $value) && strlen($value) >= 2) {
            $field['description'] = "Sample value \"{$value}\" looks like one of a fixed set; list the allowed values.";
        }

        if (is_array($value) && ! array_is_list($value)) {
            $field['type'] = 'object';
            $field['children'] = $depth < self::MAX_DEPTH ? $this->inferFields($value, $depth + 1) : [];
        } elseif (is_array($value)) {
            $field['type'] = 'array';
            $objects = array_filter(array_slice($value, 0, 20), fn (mixed $item): bool => is_array($item) && ! array_is_list($item));

            if ($objects !== [] && $depth < self::MAX_DEPTH) {
                $field['children'] = $this->inferFields(array_replace(...array_values($objects)), $depth + 1);
            } else {
                $field['item_type'] = $value === [] ? 'string' : $this->inferScalarType($value[0]);
            }
        }

        return $field;
    }

    /**
     * Infer the builder type of a scalar sample value.
     */
    protected function inferScalarType(mixed $value): string
    {
        return match (true) {
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            ! is_string($value) => 'string',
            filter_var($value, FILTER_VALIDATE_EMAIL) !== false => 'email',
            preg_match('/^https?:\/\//i', $value) === 1 => 'url',
            Str::isUuid($value) => 'uuid',
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 => 'date',
            preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $value) === 1 => 'datetime',
            default => 'string',
        };
    }

    /**
     * Convert an OpenAPI operation into an endpoint definition.
     *
     * @param  array<string, mixed>  $operation
     * @param  array<int, mixed>  $sharedParameters
     * @return array<string, mixed>
     */
    protected function operation(string $path, string $method, array $operation, array $sharedParameters): array
    {
        $method = strtoupper($method);

        $endpoint = $this->endpoint(
            name: isset($operation['operationId']) ? Str::camel((string) preg_replace('/[^A-Za-z0-9]+/', ' ', (string) $operation['operationId'])) : $this->defaultName($method, $path),
            method: $method,
            path: $path,
        );

        $endpoint['description'] = Str::limit(trim((string) ($operation['summary'] ?? $operation['description'] ?? '')), 480);

        $parameters = collect([...$sharedParameters, ...(array) ($operation['parameters'] ?? [])])
            ->map(fn (mixed $parameter): array => $this->resolve((array) $parameter))
            ->keyBy(fn (array $parameter): string => ($parameter['in'] ?? '').':'.($parameter['name'] ?? ''));

        $formFields = [];

        foreach ($parameters as $parameter) {
            $name = (string) ($parameter['name'] ?? '');
            $schema = isset($parameter['schema']) ? (array) $parameter['schema'] : $parameter;

            match ($parameter['in'] ?? null) {
                'query' => $this->pushField($endpoint['query'], $name, $schema, (bool) ($parameter['required'] ?? false), $parameter),
                'header' => $this->pushHeader($endpoint['headers'], $name, $parameter),
                'body' => $endpoint['body'] = $this->schemaFields($schema),
                'formData' => $this->pushField($formFields, $name, $schema, (bool) ($parameter['required'] ?? false), $parameter),
                default => null,
            };
        }

        if ($formFields !== []) {
            $endpoint['body'] = $formFields;
            $endpoint['body_format'] = collect($formFields)->contains('type', 'file') ? 'multipart' : 'form';
        }

        if (isset($operation['requestBody'])) {
            $this->applyRequestBody($endpoint, $this->resolve((array) $operation['requestBody']));
        }

        return $endpoint;
    }

    /**
     * Apply an OpenAPI 3 request body to an endpoint.
     *
     * @param  array<string, mixed>  $endpoint
     * @param  array<string, mixed>  $requestBody
     */
    protected function applyRequestBody(array &$endpoint, array $requestBody): void
    {
        $content = (array) ($requestBody['content'] ?? []);

        $formats = [
            'application/json' => 'json',
            'application/x-www-form-urlencoded' => 'form',
            'multipart/form-data' => 'multipart',
        ];

        foreach ($formats as $mediaType => $format) {
            if (isset($content[$mediaType])) {
                $endpoint['body'] = $this->schemaFields((array) ($content[$mediaType]['schema'] ?? []));
                $endpoint['body_format'] = $format;

                return;
            }
        }

        $first = reset($content);

        if (is_array($first)) {
            $endpoint['body'] = $this->schemaFields((array) ($first['schema'] ?? []));
        }
    }

    /**
     * Convert an object schema into field definitions.
     *
     * @param  array<string, mixed>  $schema
     * @return list<array<string, mixed>>
     */
    protected function schemaFields(array $schema, int $depth = 0): array
    {
        $schema = $this->flatten($schema, $depth);

        if (($schema['type'] ?? null) === 'array' && isset($schema['items'])) {
            $this->warnings[] = 'A request body is a top-level array; fields were taken from its item schema.';
            $schema = $this->flatten((array) $schema['items'], $depth);
        }

        $required = (array) ($schema['required'] ?? []);
        $fields = [];

        foreach ((array) ($schema['properties'] ?? []) as $name => $property) {
            if (($property['readOnly'] ?? false) === true) {
                continue;
            }

            $this->pushField($fields, (string) $name, (array) $property, in_array($name, $required, true), [], $depth);
        }

        return $fields;
    }

    /**
     * Append a field built from a schema, skipping unsupported names.
     *
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $parameter
     */
    protected function pushField(array &$fields, string $name, array $schema, bool $required, array $parameter = [], int $depth = 0): void
    {
        if (! preg_match(self::FIELD_NAME_PATTERN, $name)) {
            $this->warnings[] = "Skipped field \"{$name}\": names may only contain letters, numbers, dashes and underscores.";

            return;
        }

        $field = $this->schemaField($name, $schema, $required, $depth);

        if (blank($field['description']) && filled($parameter['description'] ?? null)) {
            $field['description'] = Str::limit((string) $parameter['description'], 250);
        }

        $fields[] = $field;
    }

    /**
     * Append a header parameter, ignoring headers managed by authentication.
     *
     * @param  list<array{key: string, value: string}>  $headers
     * @param  array<string, mixed>  $parameter
     */
    protected function pushHeader(array &$headers, string $name, array $parameter): void
    {
        if (in_array(Str::lower($name), ['authorization', 'content-type', 'accept'], true)) {
            return;
        }

        $schema = (array) ($parameter['schema'] ?? $parameter);
        $value = $parameter['example'] ?? $schema['example'] ?? $schema['default'] ?? '';

        $headers[] = ['key' => $name, 'value' => is_scalar($value) ? (string) $value : ''];
    }

    /**
     * Convert a property schema into a field definition.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    protected function schemaField(string $name, array $schema, bool $required, int $depth): array
    {
        $schema = $this->flatten($schema, $depth);
        $types = (array) ($schema['type'] ?? (isset($schema['properties']) ? 'object' : 'string'));
        $type = collect($types)->first(fn (mixed $type): bool => $type !== 'null') ?? 'string';

        $field = $this->field($name, $this->schemaType((string) $type, (string) ($schema['format'] ?? '')), $required);
        $field['nullable'] = (bool) ($schema['nullable'] ?? $schema['x-nullable'] ?? in_array('null', $types, true));
        $field['description'] = Str::limit(trim((string) ($schema['description'] ?? '')), 250);
        $field['enum'] = implode(',', array_filter((array) ($schema['enum'] ?? []), is_scalar(...)));
        $field['rules'] = implode('|', $this->constraintRules($schema));

        $example = $schema['example'] ?? $schema['default'] ?? null;
        $field['example'] = is_scalar($example) ? (string) (is_bool($example) ? ($example ? 'true' : 'false') : $example) : null;

        if ($field['type'] === 'object') {
            $field['children'] = $depth < self::MAX_DEPTH ? $this->schemaFields($schema, $depth + 1) : [];
        }

        if ($field['type'] === 'array') {
            $items = $this->flatten((array) ($schema['items'] ?? []), $depth + 1);

            if (($items['type'] ?? null) === 'object' || isset($items['properties'])) {
                $field['children'] = $depth < self::MAX_DEPTH ? $this->schemaFields($items, $depth + 1) : [];
            } else {
                $itemType = $this->schemaType((string) ($items['type'] ?? 'string'), (string) ($items['format'] ?? ''));
                $field['item_type'] = in_array($itemType, ValidationRuleBuilder::SCALAR_TYPES, true) ? $itemType : 'string';
            }
        }

        return $field;
    }

    /**
     * Map an OpenAPI type and format to a builder field type.
     */
    protected function schemaType(string $type, string $format): string
    {
        return match ($type) {
            'integer' => 'integer',
            'number' => 'number',
            'boolean' => 'boolean',
            'object' => 'object',
            'array' => 'array',
            'file' => 'file',
            default => match ($format) {
                'email' => 'email',
                'uri', 'url' => 'url',
                'uuid' => 'uuid',
                'date' => 'date',
                'date-time' => 'datetime',
                'binary' => 'file',
                default => 'string',
            },
        };
    }

    /**
     * Translate schema constraints into Laravel validation rules.
     *
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    protected function constraintRules(array $schema): array
    {
        $rules = [];

        foreach (['minLength' => 'min', 'minimum' => 'min', 'minItems' => 'min', 'maxLength' => 'max', 'maximum' => 'max', 'maxItems' => 'max'] as $key => $rule) {
            if (isset($schema[$key]) && is_numeric($schema[$key])) {
                $rules[$rule] = $rule.':'.$schema[$key];
            }
        }

        if (isset($schema['pattern']) && is_string($schema['pattern']) && ! str_contains($schema['pattern'], '|')) {
            $rules['regex'] = 'regex:/'.str_replace('/', '\/', $schema['pattern']).'/';
        }

        return array_values($rules);
    }

    /**
     * Resolve references and merge `allOf`, `oneOf` and `anyOf` schemas.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    protected function flatten(array $schema, int $depth = 0): array
    {
        $schema = $this->resolve($schema, $depth);

        if (isset($schema['allOf']) && is_array($schema['allOf'])) {
            $merged = collect($schema['allOf'])->map(fn (mixed $part): array => $this->flatten((array) $part, $depth + 1));

            $schema['properties'] = array_merge((array) ($schema['properties'] ?? []), ...$merged->pluck('properties')->filter()->all());
            $schema['required'] = array_merge((array) ($schema['required'] ?? []), ...$merged->pluck('required')->filter()->all());
            $schema['type'] ??= 'object';
            unset($schema['allOf']);
        }

        foreach (['oneOf', 'anyOf'] as $keyword) {
            if (isset($schema[$keyword][0]) && is_array($schema[$keyword][0])) {
                $this->warnings[] = "\"{$keyword}\" schemas were simplified to their first option.";
                $schema = array_merge($this->flatten($schema[$keyword][0], $depth + 1), array_diff_key($schema, [$keyword => true]));
            }
        }

        return $schema;
    }

    /**
     * Resolve a local `$ref` pointer.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    protected function resolve(array $schema, int $depth = 0): array
    {
        while (isset($schema['$ref'])) {
            $ref = (string) $schema['$ref'];

            if ($depth++ > self::MAX_DEPTH || ! str_starts_with($ref, '#/')) {
                $this->warnings[] = "Could not resolve reference \"{$ref}\".";

                return [];
            }

            $segments = array_map(
                fn (string $segment): string => str_replace(['~1', '~0'], ['/', '~'], rawurldecode($segment)),
                explode('/', substr($ref, 2)),
            );

            $schema = (array) data_get($this->document, $segments, []);
        }

        return $schema;
    }

    /**
     * Suggest an auth configuration from the document's security schemes.
     *
     * @return array<string, mixed>|null
     */
    protected function suggestedAuth(): ?array
    {
        $schemes = (array) ($this->document['components']['securitySchemes'] ?? $this->document['securityDefinitions'] ?? []);
        $scheme = $this->resolve((array) (reset($schemes) ?: []));

        return match (true) {
            ($scheme['type'] ?? null) === 'http' && Str::lower((string) ($scheme['scheme'] ?? '')) === 'bearer' => ['type' => 'bearer'],
            ($scheme['type'] ?? null) === 'http' && Str::lower((string) ($scheme['scheme'] ?? '')) === 'basic',
            ($scheme['type'] ?? null) === 'basic' => ['type' => 'basic'],
            ($scheme['type'] ?? null) === 'apiKey' => [
                'type' => 'api_key',
                'location' => ($scheme['in'] ?? 'header') === 'query' ? 'query' : 'header',
                'header_name' => ($scheme['in'] ?? 'header') === 'header' ? (string) ($scheme['name'] ?? 'X-API-Key') : null,
                'param_name' => ($scheme['in'] ?? null) === 'query' ? (string) ($scheme['name'] ?? 'api_key') : null,
            ],
            ($scheme['type'] ?? null) === 'oauth2' => ['type' => 'token_endpoint'],
            default => null,
        };
    }

    /**
     * List the server URLs declared by the document.
     *
     * @return list<string>
     */
    protected function servers(): array
    {
        if (isset($this->document['host'])) {
            $scheme = $this->document['schemes'][0] ?? 'https';

            return [$scheme.'://'.$this->document['host'].($this->document['basePath'] ?? '')];
        }

        $servers = [];

        foreach ((array) ($this->document['servers'] ?? []) as $server) {
            if (is_array($server) && is_string($server['url'] ?? null)) {
                $servers[] = $server['url'];
            }
        }

        return $servers;
    }

    /**
     * Create an empty endpoint definition.
     *
     * @return array<string, mixed>
     */
    protected function endpoint(string $name, string $method, string $path): array
    {
        return [
            'name' => Str::limit($name, 60, ''),
            'method' => $method,
            'path' => '/'.ltrim((string) preg_replace('/:([A-Za-z_][A-Za-z0-9_]*)/', '{$1}', $path), '/'),
            'description' => '',
            'body_format' => 'json',
            'store_response' => false,
            'headers' => [],
            'query' => [],
            'body' => [],
        ];
    }

    /**
     * Create an empty field definition.
     *
     * @return array<string, mixed>
     */
    protected function field(string $name, string $type, bool $required): array
    {
        return [
            'name' => $name,
            'type' => $type,
            'required' => $required,
            'nullable' => false,
            'rules' => '',
            'enum' => '',
            'description' => '',
            'example' => null,
            'item_type' => null,
            'condition' => null,
            'children' => [],
        ];
    }

    /**
     * Derive a method-style name from an HTTP method and path.
     */
    protected function defaultName(string $method, string $path): string
    {
        $words = preg_replace(['/\{([^}]+)\}/', '/:([A-Za-z_]+)/', '/[^A-Za-z0-9]+/'], [' by $1 ', ' by $1 ', ' '], $path);

        return Str::camel(Str::lower($method).' '.$words) ?: Str::lower($method).'Request';
    }

    /**
     * Make an endpoint name unique among already imported names.
     *
     * @param  list<string>  $taken
     */
    protected function uniqueName(string $name, array $taken): string
    {
        $candidate = $name;

        for ($suffix = 2; in_array(Str::lower($candidate), $taken, true); $suffix++) {
            $candidate = $name.$suffix;
        }

        return $candidate;
    }

    /**
     * Decode a JSON string.
     */
    protected function decodeJson(string $json): mixed
    {
        try {
            return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Invalid JSON: '.$exception->getMessage());
        }
    }

    /**
     * Decode an OpenAPI document from JSON or, when available, YAML.
     *
     * @return array<string, mixed>
     */
    protected function decodeDocument(string $content): array
    {
        $trimmed = ltrim($content);

        if (str_starts_with($trimmed, '{')) {
            return (array) $this->decodeJson($content);
        }

        if (! class_exists(Yaml::class)) {
            throw new InvalidArgumentException('YAML documents need the symfony/yaml package; paste the JSON version instead.');
        }

        try {
            return (array) Yaml::parse($content);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('Invalid YAML: '.$exception->getMessage());
        }
    }
}
