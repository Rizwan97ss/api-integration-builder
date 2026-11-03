<?php

namespace DevForge\IntegrationBuilder\Services;

use DevForge\IntegrationBuilder\Credentials\ProviderCredentials;
use DevForge\IntegrationBuilder\Exceptions\ApiIntegrationException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Builds authenticated HTTP requests for a provider from the configured credential source.
 *
 * Credentials are always read from the provider record at runtime; they are
 * never copied into generated code. Tokens fetched from the endpoint the
 * user marked as providing the token are cached (encrypted) instead of being
 * written back to the credential source.
 */
class IntegrationClient
{
    /**
     * Supported authentication strategies.
     *
     * @var list<string>
     */
    public const array AUTH_TYPES = [
        'none',
        'bearer',
        'api_key',
        'basic',
        'token_endpoint',
    ];

    /**
     * Strategies that fetch a short-lived token from one of the integration's endpoints.
     *
     * @var list<string>
     */
    public const array TOKEN_AUTH_TYPES = ['token_endpoint'];

    /**
     * Options understood by each authentication strategy.
     *
     * @var array<string, list<string>>
     */
    public const array AUTH_OPTIONS = [
        'none' => [],
        'bearer' => ['token_column', 'prefix'],
        'api_key' => ['key_column', 'location', 'header_name', 'param_name', 'prefix'],
        'basic' => ['username_column', 'password_column'],
        'token_endpoint' => ['header_name', 'prefix'],
    ];

    /**
     * Token lifetime used when the token response does not say when it expires.
     */
    public const int DEFAULT_TOKEN_TTL = 3600;

    /**
     * @param  array<string, mixed>  $auth
     */
    public function __construct(
        public readonly ProviderCredentials $provider,
        public readonly array $auth = ['type' => 'none'],
        public readonly ?string $baseUrlColumn = null,
        public readonly int $timeout = 30,
    ) {}

    /**
     * Reduce a builder auth definition to the options its strategy understands.
     *
     * Header rows (`[{key, value}]`) are converted into a `key => value` map.
     *
     * @param  array<string, mixed>  $auth
     * @return array<string, mixed>
     */
    public static function normalizeAuth(array $auth): array
    {
        $type = (string) ($auth['type'] ?? 'none');

        $normalized = ['type' => $type];

        foreach (self::AUTH_OPTIONS[$type] ?? [] as $option) {
            if (filled($auth[$option] ?? null)) {
                $normalized[$option] = (string) $auth[$option];
            }
        }

        if (in_array($type, self::TOKEN_AUTH_TYPES, true) && is_array($auth['endpoint'] ?? null)) {
            $normalized['endpoint'] = $auth['endpoint'];
        }

        $headers = collect((array) ($auth['headers'] ?? []))
            ->filter(fn (mixed $header): bool => is_array($header) && filled($header['key'] ?? null))
            ->mapWithKeys(fn (array $header): array => [(string) $header['key'] => (string) ($header['value'] ?? '')])
            ->all();

        if ($headers !== []) {
            $normalized['headers'] = $headers;
        }

        return $normalized;
    }

    /**
     * Get the provider's API base URL.
     */
    public function baseUrl(): string
    {
        $column = $this->baseUrlColumn ?? $this->provider->defaultBaseUrlKey();

        $url = $column !== null && in_array($column, ProviderCredentials::URL_KEYS, true)
            ? $this->provider->get($column)
            : null;

        if (blank($url)) {
            throw new ApiIntegrationException(
                sprintf('Provider "%s" has no base URL in the "%s" column.', $this->provider->name, $column ?? 'url'),
                $this->provider->name,
            );
        }

        return rtrim((string) $url, '/');
    }

    /**
     * Get the configured authentication strategy.
     */
    public function authType(): string
    {
        $type = (string) ($this->auth['type'] ?? 'none');

        if (! in_array($type, self::AUTH_TYPES, true)) {
            throw new InvalidArgumentException("Unsupported authentication type [{$type}].");
        }

        return $type;
    }

    /**
     * Determine whether the strategy relies on a fetched access token.
     */
    public function usesAccessToken(): bool
    {
        return in_array($this->authType(), self::TOKEN_AUTH_TYPES, true);
    }

    /**
     * Create an authenticated request against the provider's base URL.
     */
    public function request(): PendingRequest
    {
        return $this->authenticate(
            Http::baseUrl($this->baseUrl())
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout($this->timeout)
        );
    }

    /**
     * Apply the configured authentication to a request.
     */
    public function authenticate(PendingRequest $request): PendingRequest
    {
        $request = $request->withHeaders($this->staticHeaders());

        return match ($this->authType()) {
            'none' => $request,
            'bearer' => $request->withToken(
                $this->credential('token_column', 'token'),
                (string) ($this->auth['prefix'] ?? 'Bearer'),
            ),
            'api_key' => $this->applyApiKey($request),
            'basic' => $request->withBasicAuth(
                $this->credential('username_column', 'user_name'),
                $this->credential('password_column', 'password'),
            ),
            default => $this->applyAccessToken($request),
        };
    }

    /**
     * Send a request, refreshing the access token once if it was rejected.
     *
     * @param  array<string, scalar>  $pathParams
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public function send(
        string $method,
        string $path,
        array $pathParams = [],
        array $query = [],
        array $payload = [],
        array $headers = [],
        string $bodyFormat = 'json',
        ?callable $beforeSending = null,
    ): Response {
        $response = $this->dispatch($method, $path, $pathParams, $query, $payload, $headers, $bodyFormat, $beforeSending);

        if ($response->status() === 401 && $this->usesAccessToken()) {
            $this->accessToken(fresh: true);

            $response = $this->dispatch($method, $path, $pathParams, $query, $payload, $headers, $bodyFormat, $beforeSending);
        }

        return $response;
    }

    /**
     * Get a valid access token, fetching a new one when needed.
     */
    public function accessToken(bool $fresh = false): string
    {
        $cacheKey = $this->tokenCacheKey();

        if ($fresh) {
            Cache::forget($cacheKey);
        }

        $cached = Cache::get($cacheKey);

        if (is_string($cached)) {
            return Crypt::decryptString($cached);
        }

        return $this->refreshAccessToken()['token'];
    }

    /**
     * Fetch a new access token and cache it until shortly before it expires.
     *
     * @return array{token: string, expires_in: int}
     */
    public function refreshAccessToken(): array
    {
        $token = $this->fetchAccessToken();

        Cache::put($this->tokenCacheKey(), Crypt::encryptString($token['token']), now()->addSeconds(max(60, $token['expires_in'] - 60)));

        return $token;
    }

    /**
     * Call the integration's token endpoint and read the token from its response.
     *
     * @return array{token: string, expires_in: int}
     */
    public function fetchAccessToken(): array
    {
        $endpoint = $this->tokenEndpoint();
        $rules = new ValidationRuleBuilder;

        $request = Http::acceptJson()
            ->connectTimeout(10)
            ->timeout($this->timeout)
            ->withHeaders([...$this->staticHeaders(), ...$this->endpointHeaders($endpoint)]);

        if (filled($endpoint['basic_username'] ?? null)) {
            $request = $request->withBasicAuth(
                $this->columnValue((string) $endpoint['basic_username']),
                $this->columnValue((string) ($endpoint['basic_password'] ?? '')),
            );
        }

        $query = $this->injectedValues($rules->injectedFields((array) ($endpoint['query'] ?? [])));
        $payload = $this->injectedValues($rules->injectedFields((array) ($endpoint['body'] ?? [])));
        $method = strtoupper((string) ($endpoint['method'] ?? 'POST'));
        $options = $query === [] ? [] : ['query' => $query];

        if ($payload !== [] && $method !== 'GET') {
            $options[($endpoint['body_format'] ?? 'json') === 'form' ? 'form_params' : 'json'] = $payload;
        }

        $response = $request->send($method, $this->tokenUrl(), $options);

        if ($response->failed()) {
            throw ApiIntegrationException::authenticationFailed(
                $this->provider->name,
                sprintf('the token endpoint responded with HTTP %d.', $response->status()),
            );
        }

        $tokenPath = (string) ($endpoint['token_path'] ?? 'access_token');
        $token = data_get($response->json(), $tokenPath);

        if (! is_string($token) || $token === '') {
            throw ApiIntegrationException::authenticationFailed($this->provider->name, "no token was found at \"{$tokenPath}\" in the token endpoint response.");
        }

        $expiresIn = data_get($response->json(), (string) ($endpoint['expires_path'] ?? 'expires_in'))
            ?? $endpoint['token_ttl']
            ?? self::DEFAULT_TOKEN_TTL;

        return ['token' => $token, 'expires_in' => (int) $expiresIn];
    }

    /**
     * Get the URL of the token endpoint.
     */
    public function tokenUrl(): string
    {
        $endpoint = $this->tokenEndpoint();
        $column = $endpoint['base_url_column'] ?? null;

        if (blank($column)) {
            $base = $this->baseUrl();
        } else {
            if (! in_array($column, ProviderCredentials::URL_KEYS, true) || blank($this->provider->get($column))) {
                throw ApiIntegrationException::authenticationFailed($this->provider->name, "the \"{$column}\" column holds no URL for the token endpoint.");
            }

            $base = rtrim((string) $this->provider->get($column), '/');
        }

        $path = ltrim(self::expandPath((string) ($endpoint['path'] ?? ''), []), '/');

        return $path === '' ? $base : $base.'/'.$path;
    }

    /**
     * Get the endpoint marked as providing the token.
     *
     * @return array<string, mixed>
     */
    public function tokenEndpoint(): array
    {
        $endpoint = $this->auth['endpoint'] ?? null;

        if (! is_array($endpoint)) {
            throw ApiIntegrationException::authenticationFailed(
                $this->provider->name,
                'add an endpoint and tick "This endpoint provides the token" to fetch a token.',
            );
        }

        return $endpoint;
    }

    /**
     * Resolve fields filled from credential columns or fixed values into a nested payload.
     *
     * @param  list<array{path: string, type: string, source: string, credential: string|null, value: string|null}>  $fields
     * @return array<string, mixed>
     */
    public function injectedValues(array $fields): array
    {
        $rules = new ValidationRuleBuilder;
        $values = [];

        foreach ($fields as $field) {
            $value = $field['source'] === 'credential'
                ? $this->columnValue((string) $field['credential'])
                : $rules->castFixedValue((string) $field['value'], $field['type']);

            data_set($values, $field['path'], $value);
        }

        return $values;
    }

    /**
     * Read a credential column, failing when it is empty.
     */
    public function columnValue(string $column): string
    {
        if (! in_array($column, ProviderCredentials::CREDENTIAL_KEYS, true)) {
            throw new InvalidArgumentException("Column [{$column}] is not a credential column.");
        }

        $value = $this->provider->get($column);

        if (blank($value)) {
            throw ApiIntegrationException::authenticationFailed($this->provider->name, "the \"{$column}\" column is empty.");
        }

        return (string) $value;
    }

    /**
     * Apply a fetched access token in the configured header.
     */
    protected function applyAccessToken(PendingRequest $request): PendingRequest
    {
        $header = (string) ($this->auth['header_name'] ?? 'Authorization');
        $prefix = self::tokenPrefix($header, $this->auth['prefix'] ?? null);

        return $request->withHeaders([$header => trim($prefix.' '.$this->accessToken())]);
    }

    /**
     * Get the prefix sent before a token: "Bearer" in the Authorization header unless another is set.
     */
    public static function tokenPrefix(string $header, mixed $prefix): string
    {
        $prefix = trim((string) $prefix);

        return $prefix === '' && strcasecmp($header, 'Authorization') === 0 ? 'Bearer' : $prefix;
    }

    /**
     * Convert an endpoint's header rows into a header map.
     *
     * @param  array<string, mixed>  $endpoint
     * @return array<string, string>
     */
    protected function endpointHeaders(array $endpoint): array
    {
        return collect((array) ($endpoint['headers'] ?? []))
            ->filter(fn (mixed $header): bool => is_array($header) && filled($header['key'] ?? null))
            ->mapWithKeys(fn (array $header): array => [(string) $header['key'] => (string) ($header['value'] ?? '')])
            ->all();
    }

    /**
     * Replace `{placeholders}` in a path with URL-encoded values.
     *
     * @param  array<string, scalar>  $pathParams
     */
    public static function expandPath(string $path, array $pathParams): string
    {
        if (str_contains($path, '://') || str_starts_with($path, '//')) {
            throw new InvalidArgumentException('Endpoint paths must be relative to the provider base URL.');
        }

        return preg_replace_callback('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', function (array $match) use ($pathParams): string {
            if (! array_key_exists($match[1], $pathParams) || $pathParams[$match[1]] === '') {
                throw new InvalidArgumentException("Missing value for path parameter [{$match[1]}].");
            }

            return rawurlencode((string) $pathParams[$match[1]]);
        }, $path) ?? $path;
    }

    /**
     * Build and send a single request.
     *
     * @param  array<string, scalar>  $pathParams
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    protected function dispatch(
        string $method,
        string $path,
        array $pathParams,
        array $query,
        array $payload,
        array $headers,
        string $bodyFormat,
        ?callable $beforeSending,
    ): Response {
        $method = strtoupper($method);
        $request = $this->request()->withHeaders($headers);

        if ($beforeSending !== null) {
            $request = $request->beforeSending($beforeSending);
        }

        $options = $query === [] ? [] : ['query' => $query];

        if ($payload !== [] && $method !== 'GET') {
            match ($bodyFormat) {
                'form' => $options['form_params'] = $payload,
                'multipart' => $options['multipart'] = $this->multipart($payload),
                default => $options['json'] = $payload,
            };
        }

        return $request->send($method, ltrim(self::expandPath($path, $pathParams), '/'), $options);
    }

    /**
     * Convert a payload into multipart parts, attaching uploaded files.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    protected function multipart(array $payload): array
    {
        return collect($payload)->map(fn (mixed $value, string $name): array => match (true) {
            $value instanceof UploadedFile => [
                'name' => $name,
                'contents' => fopen($value->getRealPath(), 'r'),
                'filename' => $value->getClientOriginalName(),
            ],
            is_array($value) => ['name' => $name, 'contents' => (string) json_encode($value)],
            is_bool($value) => ['name' => $name, 'contents' => $value ? '1' : '0'],
            default => ['name' => $name, 'contents' => (string) $value],
        })->values()->all();
    }

    /**
     * Apply API key authentication as a header or query parameter.
     */
    protected function applyApiKey(PendingRequest $request): PendingRequest
    {
        $key = $this->credential('key_column', 'api_key');

        if (($this->auth['location'] ?? 'header') === 'query') {
            return $request->withQueryParameters([(string) ($this->auth['param_name'] ?? 'api_key') => $key]);
        }

        return $request->withHeaders([
            (string) ($this->auth['header_name'] ?? 'X-API-Key') => trim(($this->auth['prefix'] ?? '').' '.$key),
        ]);
    }

    /**
     * Resolve static headers, replacing `{column}` placeholders with credentials.
     *
     * @return array<string, string>
     */
    protected function staticHeaders(): array
    {
        /** @var array<string, string> $headers */
        $headers = $this->auth['headers'] ?? [];

        return collect($headers)
            ->map(fn (string $value): string => preg_replace_callback(
                '/\{([a-z_]+)\}/',
                fn (array $match): string => in_array($match[1], ProviderCredentials::CREDENTIAL_KEYS, true)
                    ? (string) $this->provider->get($match[1])
                    : $match[0],
                $value,
            ) ?? $value)
            ->all();
    }

    /**
     * Read a required credential column from the provider.
     */
    protected function credential(string $option, string $defaultColumn): string
    {
        $value = $this->optionalCredential($option, $defaultColumn);

        if ($value === null) {
            $column = $this->auth[$option] ?? $defaultColumn;

            throw ApiIntegrationException::authenticationFailed($this->provider->name, "the \"{$column}\" column is empty.");
        }

        return $value;
    }

    /**
     * Read an optional credential column from the provider.
     */
    protected function optionalCredential(string $option, string $defaultColumn): ?string
    {
        $column = (string) ($this->auth[$option] ?? $defaultColumn);

        if (! in_array($column, ProviderCredentials::CREDENTIAL_KEYS, true)) {
            throw new InvalidArgumentException("Column [{$column}] is not a credential column.");
        }

        $value = $this->provider->get($column);

        return blank($value) ? null : (string) $value;
    }

    /**
     * Get the cache key for this provider and auth configuration's token.
     */
    protected function tokenCacheKey(): string
    {
        return sprintf('integration-token:%s:%s', $this->provider->id, sha1((string) json_encode($this->auth)));
    }
}
