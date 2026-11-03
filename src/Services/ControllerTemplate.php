<?php

namespace Rizwan\IntegrationBuilder\Services;

use Illuminate\Support\Str;
use Rizwan\IntegrationBuilder\Credentials\CredentialResolver;
use Rizwan\IntegrationBuilder\Credentials\ProviderCredentials;

/**
 * Renders a self-contained API controller in the project's integration style.
 *
 * The controller loads its provider from the configured credential source
 * (see {@see CredentialResolver::generatedLoader()}) in the constructor and has private `headers()`, `getAuthToken()` and `request()`
 * helpers. Endpoint methods get a token, call `request()` and return its
 * result as JSON, so they also work when appended to an existing,
 * hand-written controller that defines the same helpers.
 *
 * With the "token from an endpoint" auth type, `getAuthToken()` calls the
 * endpoint the user marked as providing the token and caches the result.
 */
class ControllerTemplate
{
    use RendersPhp;

    /**
     * Property names used for credential columns.
     *
     * @var array<string, string>
     */
    public const array PROPERTY_NAMES = [
        'api_key' => 'apiKey',
        'secret_key' => 'secretKey',
        'token' => 'authToken',
        'refresh_token' => 'refreshToken',
        'user_name' => 'userName',
        'password' => 'password',
        'tenant_id' => 'tenantId',
    ];

    /**
     * Helper methods every generated controller relies on.
     *
     * @var list<string>
     */
    public const array HELPERS = ['getAuthToken', 'request'];

    /**
     * @var array<string, mixed>
     */
    protected array $auth;

    protected string $type;

    protected string $title;

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, string>  $names
     * @param  array<string, mixed>|null  $tokenEndpoint  The described endpoint that provides the token.
     */
    public function __construct(
        protected array $definition,
        protected ProviderCredentials $provider,
        protected array $names,
        protected CredentialResolver $credentials,
        protected ?array $tokenEndpoint = null,
    ) {
        $this->auth = IntegrationClient::normalizeAuth((array) $definition['auth']);
        $this->type = (string) $this->auth['type'];
        $this->title = $this->sanitizeComment($names['title']);
    }

    /**
     * Determine whether requests carry a token from getAuthToken().
     */
    public function usesBearerToken(): bool
    {
        return $this->type === 'bearer' || $this->fetchesToken();
    }

    /**
     * Determine whether getAuthToken() fetches the token from an endpoint.
     */
    public function fetchesToken(): bool
    {
        return in_array($this->type, IntegrationClient::TOKEN_AUTH_TYPES, true);
    }

    /**
     * Determine whether an endpoint method reads the incoming request.
     *
     * @param  array<string, mixed>  $endpoint
     */
    public function needsRequest(array $endpoint): bool
    {
        return $endpoint['has_fields'] || $this->forwardsRawBody($endpoint);
    }

    /**
     * Determine whether an endpoint defines no fields at all, so its body is forwarded as is.
     *
     * @param  array<string, mixed>  $endpoint
     */
    protected function forwardsRawBody(array $endpoint): bool
    {
        return $endpoint['has_body'] && ! $endpoint['has_fields'] && $endpoint['injected_query'] === [] && $endpoint['injected_body'] === [];
    }

    /**
     * Get the header that carries an idempotency reference, if any.
     */
    public function idempotencyHeader(): ?string
    {
        $header = trim((string) ($this->definition['options']['idempotency_header'] ?? ''));

        return $header === '' ? null : $header;
    }

    /**
     * Render a complete controller file.
     *
     * @param  array<string, string>  $methods  Public methods keyed by name.
     * @param  list<string>  $imports
     */
    public function file(array $methods, array $imports): string
    {
        $members = [
            $this->properties(),
            $this->constructorMethod(),
            $this->headersMethod(),
            $this->getAuthTokenMethod(),
            $this->requestMethod(),
            ...array_values($methods),
        ];

        return implode("\n", [
            '<?php',
            '',
            'namespace App\\Http\\Controllers;',
            '',
            ...array_map(fn (string $import): string => "use {$import};", $imports),
            '',
            '/**',
            " * {$this->title} API integration.",
            ' *',
            ' * '.StorageSchema::MARKER,
            ' */',
            "class {$this->names['controller']} extends Controller",
            '{',
            implode("\n\n", $members),
            '}',
            '',
        ]);
    }

    /**
     * List the imports the controller's own helpers need.
     *
     * @return list<string>
     */
    public function helperImports(): array
    {
        $imports = [
            ...$this->loader()['imports'],
            'Illuminate\\Http\\Client\\ConnectionException',
            'Illuminate\\Support\\Facades\\Http',
            'Illuminate\\Support\\Facades\\Log',
        ];

        if ($this->fetchesToken()) {
            $imports[] = 'Illuminate\\Support\\Facades\\Cache';
        }

        if ($this->fetchesToken() && ($this->tokenEndpoint['body_format'] ?? 'json') === 'form') {
            $imports[] = 'Illuminate\\Support\\Arr';
        }

        return $imports;
    }

    /**
     * Render the public method for the endpoint that provides the token.
     *
     * It fetches a fresh token and caches it for the other methods; the
     * token itself is never returned to the browser.
     *
     * @param  array<string, mixed>  $endpoint
     */
    public function tokenEndpointMethod(array $endpoint): string
    {
        $doc = [
            $endpoint['description'] !== '' ? $endpoint['description'] : $endpoint['title'].'.',
            '',
            "{$endpoint['http_method']} {$endpoint['path']}",
            '',
            'Fetches a fresh token and caches it for the other methods. The token itself is not returned.',
        ];

        return implode("\n", [
            $this->docBlock($doc, 1),
            $this->indent(1)."public function {$endpoint['method']}(): JsonResponse",
            $this->indent(1).'{',
            $this->indent(2).'$auth = $this->getAuthToken(true);',
            '',
            $this->indent(2).'return response()->json([',
            $this->indent(3)."'success' => \$auth['success'],",
            $this->indent(3)."'message' => \$auth['success'] ? ".$this->export("Fetched a new {$this->title} token.")." : \$auth['message'],",
            $this->indent(2)."], (int) (\$auth['status'] ?? 500));",
            $this->indent(1).'}',
        ]);
    }

    /**
     * Render the public method that calls an endpoint.
     *
     * @param  array<string, mixed>  $endpoint
     */
    public function endpointMethod(array $endpoint): string
    {
        $parameters = [];
        $lines = [];
        $bodyFields = $endpoint['body'] !== [];
        $queryNames = $this->exportInline($endpoint['query_names']);
        $splitsInput = $endpoint['has_query'] && $bodyFields;
        $injectsBody = $endpoint['injected_body'] !== [] && $endpoint['has_body'];

        if ($this->needsRequest($endpoint)) {
            $parameters[] = 'Request $request';
        }

        foreach ($endpoint['path_params'] as $variable) {
            $parameters[] = "string \${$variable}";
        }

        if ($endpoint['has_fields']) {
            $lines[] = $this->indent(2).'$validated = $request->validate('.$this->rulesArray($endpoint['rules'], 2).');';
            $lines[] = '';
        }

        if ($endpoint['requires_auth']) {
            array_push($lines, ...[...$this->authBlock(2), '']);
        }

        $payload = match (true) {
            ! $endpoint['has_body'] => null,
            $this->forwardsRawBody($endpoint) => '$request->all()',
            ! $bodyFields => $injectsBody ? '[]' : null,
            $splitsInput => "Arr::except(\$validated, {$queryNames})",
            default => '$validated',
        };

        if ($payload !== null) {
            $lines[] = $this->indent(2)."\$payload = {$payload};";
            array_push($lines, ...$this->injectionLines('$payload', $injectsBody ? $endpoint['injected_body'] : [], 2));
            $lines[] = '';
        }

        $endpointExpression = $this->pathExpression($endpoint['path'], $endpoint['path_params']);

        if ($endpoint['has_query'] || $endpoint['injected_query'] !== []) {
            $query = match (true) {
                ! $endpoint['has_query'] => '[]',
                $splitsInput => "Arr::only(\$validated, {$queryNames})",
                default => '$validated',
            };

            $lines[] = $this->indent(2)."\$endpoint = {$endpointExpression};";
            $lines[] = $this->indent(2)."\$query = {$query};";
            array_push($lines, ...$this->injectionLines('$query', $endpoint['injected_query'], 2));
            $lines[] = '';
            $lines[] = $this->indent(2).'if ($query !== []) {';
            $lines[] = $this->indent(3).'$endpoint .= \'?\'.http_build_query($query);';
            $lines[] = $this->indent(2).'}';
            $lines[] = '';
            $endpointExpression = '$endpoint';
        }

        $arguments = [
            $this->export($endpoint['http_method']),
            $endpointExpression,
            $payload === null ? '[]' : '$payload',
            $endpoint['requires_auth'] ? "\$auth['token']" : 'null',
        ];

        if ($this->idempotencyHeader() !== null && $endpoint['http_method'] !== 'GET') {
            $arguments[] = 'Str::uuid()->toString()';
        }

        while (in_array(end($arguments), ['null', '[]'], true)) {
            array_pop($arguments);
        }

        $lines[] = $this->indent(2).'$result = '.$this->call('$this->request', $arguments, 2).';';
        $lines[] = '';

        if ($endpoint['requires_auth'] && $this->fetchesToken()) {
            array_push(
                $lines,
                $this->indent(2)."if (\$result['status'] === 401) {",
                $this->indent(3).'$auth = $this->getAuthToken(true);',
                '',
                $this->indent(3)."if (\$auth['success']) {",
                $this->indent(4).'$result = '.$this->call('$this->request', $arguments, 4).';',
                $this->indent(3).'}',
                $this->indent(2).'}',
                '',
            );
        }

        if ($endpoint['storage'] !== null) {
            $lines[] = $this->indent(2)."if (\$result['success']) {";
            $lines[] = $this->indent(3)."{$endpoint['storage']['model']}::create([";

            foreach ($endpoint['storage']['columns'] as $column) {
                $value = $payload === null ? 'null' : '$payload['.$this->export($column['field']).'] ?? null';
                $lines[] = $this->indent(4).$this->export($column['column'])." => {$value},";
            }

            $lines[] = $this->indent(4)."'external_id' => data_get(\$result, 'data.id'),";
            $lines[] = $this->indent(4)."'response' => \$result['data'] ?? null,";
            $lines[] = $this->indent(3).']);';
            $lines[] = $this->indent(2).'}';
            $lines[] = '';
        }

        $lines[] = $this->indent(2)."return response()->json(\$result, (int) (\$result['status'] ?? 500));";

        $doc = [
            $endpoint['description'] !== '' ? $endpoint['description'] : $endpoint['title'].'.',
            '',
            "{$endpoint['http_method']} {$endpoint['path']}",
        ];

        if ($this->forwardsRawBody($endpoint)) {
            array_push($doc, '', 'No fields were defined, so the request body is forwarded without validation.');
        }

        return implode("\n", [
            $this->docBlock($doc, 1),
            $this->indent(1)."public function {$endpoint['method']}(".implode(', ', $parameters).'): JsonResponse',
            $this->indent(1).'{',
            ...$lines,
            $this->indent(1).'}',
        ]);
    }

    /**
     * Render validation rules as a multi-line array for $request->validate().
     *
     * @param  array<string, list<string>>  $rules
     */
    protected function rulesArray(array $rules, int $depth): string
    {
        $lines = [];

        foreach ($rules as $key => $fieldRules) {
            $lines[] = $this->indent($depth + 1).$this->export($key).' => '.$this->exportInline($fieldRules).',';
        }

        return "[\n".implode("\n", $lines)."\n".$this->indent($depth).']';
    }

    /**
     * Render the lines that fetch a token and stop when it fails.
     *
     * @return list<string>
     */
    protected function authBlock(int $depth): array
    {
        return [
            $this->indent($depth).'$auth = $this->getAuthToken();',
            '',
            $this->indent($depth)."if (! \$auth['success']) {",
            $this->indent($depth + 1).'return response()->json([',
            $this->indent($depth + 2)."'success' => false,",
            $this->indent($depth + 2)."'message' => \$auth['message'],",
            $this->indent($depth + 1)."], (int) (\$auth['status'] ?? 500));",
            $this->indent($depth).'}',
        ];
    }

    /**
     * Render the credential properties.
     */
    protected function properties(): string
    {
        $lines = [$this->indent(1).'protected string $baseUrl;'];

        if ($this->tokenUrlColumn() !== null) {
            $lines[] = $this->indent(1).'protected string $authUrl;';
        }

        foreach ($this->credentialColumns() as $column) {
            $lines[] = $this->indent(1).'protected ?string $'.self::PROPERTY_NAMES[$column].';';
        }

        return implode("\n\n", $lines);
    }

    /**
     * Render the constructor that loads the provider's URLs and credentials.
     */
    protected function constructorMethod(): string
    {
        $loader = $this->loader();
        $lines = array_map(fn (string $statement): string => $this->indent(2).$statement, $loader['statements']);

        if ($lines !== []) {
            $lines[] = '';
        }

        $lines[] = $this->indent(2).'$this->baseUrl = rtrim((string) '.$loader['values'][$this->baseUrlColumn()].", '/');";

        if (($tokenUrlColumn = $this->tokenUrlColumn()) !== null) {
            $lines[] = $this->indent(2).'$this->authUrl = rtrim((string) '.$loader['values'][$tokenUrlColumn].", '/');";
        }

        foreach ($this->credentialColumns() as $column) {
            $lines[] = $this->indent(2).'$this->'.self::PROPERTY_NAMES[$column].' = '.$loader['values'][$column].';';
        }

        return implode("\n", [
            $this->docBlock([$this->sanitizeComment($loader['description'])], 1),
            $this->indent(1).'public function __construct()',
            $this->indent(1).'{',
            ...$lines,
            $this->indent(1).'}',
        ]);
    }

    /**
     * Describe how the generated constructor loads the values it needs.
     *
     * @return array{imports: list<string>, statements: list<string>, values: array<string, string>, description: string}
     */
    protected function loader(): array
    {
        $keys = array_values(array_unique(array_filter([
            $this->baseUrlColumn(),
            $this->tokenUrlColumn(),
            ...$this->credentialColumns(),
        ])));

        return $this->credentials->generatedLoader($this->provider, $keys);
    }

    /**
     * Render the common headers helper.
     */
    protected function headersMethod(): string
    {
        $headers = [];

        foreach ((array) ($this->auth['headers'] ?? []) as $name => $value) {
            $headers[(string) $name] = $this->templateExpression((string) $value);
        }

        if ($this->type === 'api_key' && ($this->auth['location'] ?? 'header') === 'header') {
            $prefix = trim((string) ($this->auth['prefix'] ?? ''));
            $headers[(string) ($this->auth['header_name'] ?? 'X-API-Key')] = $prefix === ''
                ? '(string) $this->apiKey'
                : $this->export($prefix.' ').'.$this->apiKey';
        }

        if ($this->type === 'basic') {
            $headers['Authorization'] = "'Basic '.base64_encode(\$this->userName.':'.\$this->password)";
        }

        $headers['Accept'] = "'application/json'";
        $headers['Content-Type'] = "'application/json'";

        $lines = [$this->indent(2).'$headers = '.$this->exportExpressions($headers, 2).';', ''];

        if ($this->usesBearerToken()) {
            $header = $this->fetchesToken() ? (string) ($this->auth['header_name'] ?? 'Authorization') : 'Authorization';
            $prefix = IntegrationClient::tokenPrefix($header, $this->auth['prefix'] ?? null);

            array_push(
                $lines,
                $this->indent(2).'if ($authToken) {',
                $this->indent(3).'$headers['.$this->export($header).'] = '.($prefix === '' ? '$authToken' : $this->export($prefix.' ').'.$authToken').';',
                $this->indent(2).'}',
                '',
            );
        }

        if (($idempotency = $this->idempotencyHeader()) !== null) {
            array_push(
                $lines,
                $this->indent(2).'if ($idempotencyRef) {',
                $this->indent(3).'$headers['.$this->export($idempotency).'] = $idempotencyRef;',
                $this->indent(2).'}',
                '',
            );
        }

        $lines[] = $this->indent(2).'return $headers;';

        return implode("\n", [
            $this->docBlock(["Common headers for {$this->title} requests.", '', '@return array<string, string>'], 1),
            $this->indent(1).'private function headers(?string $authToken = null, ?string $idempotencyRef = null): array',
            $this->indent(1).'{',
            ...$lines,
            $this->indent(1).'}',
        ]);
    }

    /**
     * Render the helper that returns a token for authenticated requests.
     */
    protected function getAuthTokenMethod(): string
    {
        $success = "['success' => true, 'status' => 200, 'message' => null, 'token' => %s]";

        $lines = match (true) {
            $this->type === 'bearer' => [
                $this->indent(2).'if (blank($this->authToken)) {',
                $this->indent(3)."return ['success' => false, 'status' => 500, 'message' => ".$this->export("No token is stored for {$this->provider->name}.").", 'token' => null];",
                $this->indent(2).'}',
                '',
                $this->indent(2).'return '.sprintf($success, '(string) $this->authToken').';',
            ],
            $this->fetchesToken() && $this->tokenEndpoint !== null => $this->tokenRequestLines($success, $this->tokenEndpoint),
            $this->fetchesToken() => [
                $this->indent(2)."return ['success' => false, 'status' => 500, 'message' => ".$this->export("No {$this->title} endpoint is set up to provide the token.").", 'token' => null];",
            ],
            default => [$this->indent(2).'return '.sprintf($success, 'null').';'],
        };

        $doc = $this->fetchesToken() && $this->tokenEndpoint !== null
            ? ["Get an auth token for {$this->title} requests from {$this->tokenEndpoint['method']}(), cached until it expires.", '', '@return array<string, mixed>']
            : ["Get an auth token for {$this->title} requests.", '', '@return array<string, mixed>'];

        return implode("\n", [
            $this->docBlock($doc, 1),
            $this->indent(1).'private function getAuthToken('.($this->fetchesToken() ? 'bool $refresh = false' : '').'): array',
            $this->indent(1).'{',
            ...$lines,
            $this->indent(1).'}',
        ]);
    }

    /**
     * Render the lines that call the token endpoint, caching the token until it expires.
     *
     * @param  array<string, mixed>  $endpoint
     * @return list<string>
     */
    protected function tokenRequestLines(string $success, array $endpoint): array
    {
        $cacheKey = $this->export(Str::snake($this->names['studly']).'_auth_token');
        $timeout = (int) ($this->definition['options']['timeout'] ?? 30);
        $method = $endpoint['http_method'];
        $isForm = $endpoint['body_format'] === 'form';
        $hasPayload = $endpoint['injected_body'] !== [] && $method !== 'GET';

        $lines = [
            $this->indent(2).'if ($refresh) {',
            $this->indent(3)."Cache::forget({$cacheKey});",
            $this->indent(2).'}',
            '',
            $this->indent(2)."\$cached = Cache::get({$cacheKey});",
            '',
            $this->indent(2).'if (is_string($cached)) {',
            $this->indent(3).'return '.sprintf($success, 'decrypt($cached)').';',
            $this->indent(2).'}',
            '',
            $this->indent(2).'$url = '.$this->tokenUrlExpression($endpoint).';',
        ];

        if ($endpoint['injected_query'] !== []) {
            $lines[] = $this->indent(2).'$url .= \'?\'.http_build_query('.$this->exportExpressions($this->injectedTree($endpoint['injected_query']), 2).');';
        }

        $lines[] = '';

        if ($hasPayload) {
            $lines[] = $this->indent(2).'$payload = '.$this->exportExpressions($this->injectedTree($endpoint['injected_body']), 2).';';
            $lines[] = '';
        }

        $client = $isForm
            ? [
                $this->indent(3).'$response = Http::asForm()',
                $this->indent(4).'->acceptJson()',
                $this->indent(4)."->withHeaders(Arr::except(\$this->headers(), ['Content-Type']))",
            ]
            : [$this->indent(3).'$response = Http::withHeaders($this->headers())'];

        if ($endpoint['token']['basic_username'] !== null) {
            $client[] = $this->indent(4).'->withBasicAuth((string) $this->'.self::PROPERTY_NAMES[$endpoint['token']['basic_username']]
                .', (string) $this->'.self::PROPERTY_NAMES[(string) $endpoint['token']['basic_password']].')';
        }

        $send = $hasPayload
            ? $this->indent(4).'->send('.$this->export($method).', $url, ['.$this->export($isForm ? 'form_params' : 'json').' => $payload]);'
            : $this->indent(4).'->send('.$this->export($method).', $url);';

        return [
            ...$lines,
            $this->indent(2).'try {',
            ...$client,
            $this->indent(4)."->timeout({$timeout})",
            $send,
            $this->indent(2).'} catch (ConnectionException $exception) {',
            $this->indent(3).'Log::error('.$this->export("{$this->title} authentication failed").", ['message' => \$exception->getMessage()]);",
            '',
            $this->indent(3)."return ['success' => false, 'status' => 503, 'message' => \$exception->getMessage(), 'token' => null];",
            $this->indent(2).'}',
            '',
            $this->indent(2).'$token = $response->json('.$this->export($endpoint['token']['path']).');',
            '',
            $this->indent(2).'if ($response->failed() || ! is_string($token) || $token === \'\') {',
            $this->indent(3).'Log::error('.$this->export("{$this->title} authentication failed").", ['status' => \$response->status(), 'response' => \$response->json() ?? \$response->body()]);",
            '',
            $this->indent(3)."return ['success' => false, 'status' => \$response->failed() ? \$response->status() : 502, 'message' => ".$this->export("{$this->title} did not return an auth token.").", 'token' => null];",
            $this->indent(2).'}',
            '',
            $this->indent(2)."Cache::put({$cacheKey}, encrypt(\$token), now()->addSeconds(max(60, (int) (\$response->json(".$this->export($endpoint['token']['expires_path']).") ?? {$endpoint['token']['ttl']}) - 60)));",
            '',
            $this->indent(2).'return '.sprintf($success, '$token').';',
        ];
    }

    /**
     * Render the lines that add credential and fixed values to a payload or query array.
     *
     * @param  list<array{path: string, type: string, source: string, credential: string|null, value: string|null}>  $fields
     * @return list<string>
     */
    protected function injectionLines(string $variable, array $fields, int $depth): array
    {
        return array_map(fn (array $field): string => str_contains($field['path'], '.')
            ? $this->indent($depth)."data_set({$variable}, ".$this->export($field['path']).', '.$this->injectedExpression($field).');'
            : $this->indent($depth)."{$variable}[".$this->export($field['path']).'] = '.$this->injectedExpression($field).';', $fields);
    }

    /**
     * Build a nested array of PHP expressions for credential and fixed values.
     *
     * @param  list<array{path: string, type: string, source: string, credential: string|null, value: string|null}>  $fields
     * @return array<string, mixed>
     */
    protected function injectedTree(array $fields): array
    {
        $tree = [];

        foreach ($fields as $field) {
            data_set($tree, $field['path'], $this->injectedExpression($field));
        }

        return $tree;
    }

    /**
     * Render the expression for a credential or fixed field value.
     *
     * @param  array{path: string, type: string, source: string, credential: string|null, value: string|null}  $field
     */
    protected function injectedExpression(array $field): string
    {
        if ($field['source'] === 'credential') {
            return '$this->'.(self::PROPERTY_NAMES[(string) $field['credential']] ?? 'apiKey');
        }

        return $this->export((new ValidationRuleBuilder)->castFixedValue((string) $field['value'], $field['type']));
    }

    /**
     * Render the generic request helper.
     */
    protected function requestMethod(): string
    {
        $timeout = (int) ($this->definition['options']['timeout'] ?? 30);
        $label = $this->title;

        $url = [$this->indent(2).'$url = $this->baseUrl.\'/\'.ltrim($endpoint, \'/\');'];

        if ($this->type === 'api_key' && ($this->auth['location'] ?? 'header') === 'query') {
            $url[] = $this->indent(2).'$url .= (str_contains($url, \'?\') ? \'&\' : \'?\').http_build_query(['.$this->export((string) ($this->auth['param_name'] ?? 'api_key')).' => (string) $this->apiKey]);';
        }

        return implode("\n", [
            $this->docBlock([
                "Send a request to the {$label} API.",
                '',
                '@param  array<string, mixed>  $payload',
                '@return array<string, mixed>',
            ], 1),
            $this->indent(1).'private function request(string $method, string $endpoint, array $payload = [], ?string $authToken = null, ?string $idempotencyRef = null): array',
            $this->indent(1).'{',
            ...$url,
            '',
            $this->indent(2).'try {',
            $this->indent(3).'$response = Http::withHeaders($this->headers($authToken, $idempotencyRef))',
            $this->indent(4)."->timeout({$timeout})",
            $this->indent(4).'->send($method, $url, $payload === [] ? [] : [\'json\' => $payload]);',
            $this->indent(2).'} catch (ConnectionException $exception) {',
            $this->indent(3).'Log::error('.$this->export("{$label} API connection failed").', [',
            $this->indent(4)."'method' => \$method,",
            $this->indent(4)."'endpoint' => \$endpoint,",
            $this->indent(4)."'message' => \$exception->getMessage(),",
            $this->indent(3).']);',
            '',
            $this->indent(3)."return ['success' => false, 'status' => 503, 'message' => \$exception->getMessage()];",
            $this->indent(2).'}',
            '',
            $this->indent(2).'$data = $response->json() ?? $response->body();',
            '',
            $this->indent(2).'if ($response->failed()) {',
            $this->indent(3).'Log::error('.$this->export("{$label} API error").', [',
            $this->indent(4)."'method' => \$method,",
            $this->indent(4)."'endpoint' => \$endpoint,",
            $this->indent(4)."'status' => \$response->status(),",
            $this->indent(4)."'response' => \$data,",
            $this->indent(3).']);',
            $this->indent(2).'} else {',
            $this->indent(3).'Log::info('.$this->export("{$label} API response").', [',
            $this->indent(4)."'method' => \$method,",
            $this->indent(4)."'endpoint' => \$endpoint,",
            $this->indent(4)."'status' => \$response->status(),",
            $this->indent(3).']);',
            $this->indent(2).'}',
            '',
            $this->indent(2).'return [',
            $this->indent(3)."'success' => \$response->successful(),",
            $this->indent(3)."'status' => \$response->status(),",
            $this->indent(3)."'data' => \$data,",
            $this->indent(2).'];',
            $this->indent(1).'}',
        ]);
    }

    /**
     * List the credential keys the controller reads from the credential source.
     *
     * Includes the columns used by the auth settings, by `{column}` header
     * placeholders and by fields filled from a credential.
     *
     * @return list<string>
     */
    public function credentialColumns(): array
    {
        $options = match ($this->type) {
            'bearer' => [['token_column', 'token']],
            'api_key' => [['key_column', 'api_key']],
            'basic' => [['username_column', 'user_name'], ['password_column', 'password']],
            default => [],
        };

        $columns = array_map(fn (array $option): string => (string) ($this->auth[$option[0]] ?? $option[1]), $options);

        foreach ((array) ($this->auth['headers'] ?? []) as $value) {
            preg_match_all('/\{([a-z_]+)\}/', (string) $value, $matches);
            array_push($columns, ...$matches[1]);
        }

        $rules = new ValidationRuleBuilder;

        foreach ((array) ($this->definition['endpoints'] ?? []) as $endpoint) {
            if ($this->fetchesToken() && ($endpoint['provides_token'] ?? false)) {
                array_push($columns, ...array_filter([$endpoint['basic_username'] ?? null, $endpoint['basic_password'] ?? null], is_string(...)));
            }

            foreach ([...$rules->injectedFields((array) ($endpoint['query'] ?? [])), ...$rules->injectedFields((array) ($endpoint['body'] ?? []))] as $field) {
                if ($field['credential'] !== null) {
                    $columns[] = $field['credential'];
                }
            }
        }

        return array_values(array_unique(array_intersect($columns, ProviderCredentials::CREDENTIAL_KEYS)));
    }

    /**
     * Get the URL column the token endpoint uses when it differs from the API base URL.
     */
    protected function tokenUrlColumn(): ?string
    {
        $column = $this->fetchesToken() ? ($this->tokenEndpoint['token']['base_url_column'] ?? null) : null;

        return $column === null || $column === $this->baseUrlColumn() ? null : (string) $column;
    }

    /**
     * Get the column holding the API base URL.
     */
    protected function baseUrlColumn(): string
    {
        return (string) ($this->definition['base_url_column'] ?? $this->provider->defaultBaseUrlKey() ?? 'url');
    }

    /**
     * Render the expression for the token endpoint URL.
     *
     * @param  array<string, mixed>  $endpoint
     */
    protected function tokenUrlExpression(array $endpoint): string
    {
        $base = $this->tokenUrlColumn() === null ? '$this->baseUrl' : '$this->authUrl';
        $path = trim((string) $endpoint['path'], '/');

        return $path === '' ? $base : $base.'.'.$this->export('/'.$path);
    }

    /**
     * Render an endpoint path, interpolating path parameters.
     *
     * @param  array<string, string>  $pathParams
     */
    protected function pathExpression(string $path, array $pathParams): string
    {
        $parts = preg_split('/(\{[A-Za-z_][A-Za-z0-9_]*\})/', ltrim($path, '/'), -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [''];

        $pieces = array_map(function (string $part) use ($pathParams): string {
            if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)\}$/', $part, $match) && isset($pathParams[$match[1]])) {
                return 'rawurlencode($'.$pathParams[$match[1]].')';
            }

            return $this->export($part);
        }, $parts);

        return implode('.', $pieces);
    }

    /**
     * Render a header value, replacing `{column}` placeholders with properties.
     */
    protected function templateExpression(string $value): string
    {
        $parts = preg_split('/(\{[a-z_]+\})/', $value, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];

        $pieces = array_map(function (string $part): string {
            if (preg_match('/^\{([a-z_]+)\}$/', $part, $match) && isset(self::PROPERTY_NAMES[$match[1]])) {
                return '(string) $this->'.self::PROPERTY_NAMES[$match[1]];
            }

            return $this->export($part);
        }, $parts);

        return $pieces === [] ? "''" : implode('.', $pieces);
    }
}
