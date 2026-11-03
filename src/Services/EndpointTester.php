<?php

namespace DevForge\ApiDev\Services;

use DevForge\ApiDev\Exceptions\ApiIntegrationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Runs live authentication and endpoint tests from the builder.
 *
 * Results are safe to show in the browser: credential headers and query
 * parameters are masked.
 */
class EndpointTester
{
    /**
     * Header and parameter name fragments whose values are masked.
     *
     * @var list<string>
     */
    protected const array SENSITIVE_NAMES = ['authorization', 'key', 'token', 'secret', 'password', 'signature', 'cookie', 'auth'];

    public function __construct(protected ValidationRuleBuilder $rules) {}

    /**
     * Check that the configured authentication can be applied or exchanged for a token.
     *
     * @return array<string, mixed>
     */
    public function testAuthentication(IntegrationClient $client): array
    {
        $startedAt = hrtime(true);

        try {
            if ($client->usesAccessToken()) {
                $token = $client->fetchAccessToken();

                return [
                    'success' => true,
                    'message' => 'Access token received from the token endpoint.',
                    'duration_ms' => $this->elapsed($startedAt),
                    'details' => [
                        'base_url' => $client->baseUrl(),
                        'token_url' => $client->tokenUrl(),
                        'token' => $this->mask($token['token']),
                        'expires_in' => $token['expires_in'],
                    ],
                ];
            }

            $options = $client->request()->getOptions();

            return [
                'success' => true,
                'message' => $client->authType() === 'none'
                    ? 'No authentication is required.'
                    : 'Credentials are present. Test an endpoint to confirm the provider accepts them.',
                'duration_ms' => $this->elapsed($startedAt),
                'details' => [
                    'base_url' => $client->baseUrl(),
                    'headers' => $this->maskValues(array_diff_key((array) ($options['headers'] ?? []), ['User-Agent' => true, 'Accept' => true])),
                    'query' => $this->maskValues((array) ($options['query'] ?? [])),
                    'basic_auth' => isset($options['auth'][0]) ? $options['auth'][0].':'.$this->mask((string) ($options['auth'][1] ?? '')) : null,
                ],
            ];
        } catch (ConnectionException $exception) {
            return $this->failure('Could not reach the token endpoint: '.$exception->getMessage(), $startedAt);
        } catch (ApiIntegrationException|InvalidArgumentException $exception) {
            return $this->failure($exception->getMessage(), $startedAt);
        }
    }

    /**
     * Send a live request to an endpoint and describe the exchange.
     *
     * @param  array<string, mixed>  $endpoint
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function testEndpoint(IntegrationClient $client, array $endpoint, array $input): array
    {
        if ($endpoint['provides_token'] ?? false) {
            return $this->testAuthentication(new IntegrationClient(
                $client->provider,
                ['type' => 'token_endpoint', 'endpoint' => $endpoint, 'headers' => $client->auth['headers'] ?? []],
                $client->baseUrlColumn,
                $client->timeout,
            ));
        }

        if ($client->usesAccessToken() && ! ($endpoint['requires_auth'] ?? true)) {
            $client = new IntegrationClient(
                $client->provider,
                ['type' => 'none', 'headers' => $client->auth['headers'] ?? []],
                $client->baseUrlColumn,
                $client->timeout,
            );
        }

        $startedAt = hrtime(true);
        $query = (array) ($input['query'] ?? []);
        $body = (array) ($input['body'] ?? []);

        if (! ($input['skip_validation'] ?? false)) {
            $errors = $this->validationErrors($endpoint, $query, $body);

            if ($errors !== []) {
                return [
                    ...$this->failure('The test input does not pass the endpoint\'s validation rules, so nothing was sent.', $startedAt),
                    'validation_errors' => $errors,
                ];
            }
        }

        $headers = collect([...(array) ($endpoint['headers'] ?? []), ...(array) ($input['headers'] ?? [])])
            ->filter(fn (mixed $header): bool => is_array($header) && filled($header['key'] ?? null))
            ->mapWithKeys(fn (array $header): array => [(string) $header['key'] => (string) ($header['value'] ?? '')])
            ->all();

        $sent = null;

        try {
            $query = array_replace_recursive($query, $client->injectedValues($this->rules->injectedFields((array) ($endpoint['query'] ?? []))));
            $body = array_replace_recursive($body, $client->injectedValues($this->rules->injectedFields((array) ($endpoint['body'] ?? []))));

            $response = $client->send(
                (string) $endpoint['method'],
                (string) $endpoint['path'],
                pathParams: array_map(strval(...), array_filter((array) ($input['path_params'] ?? []), is_scalar(...))),
                query: $query,
                payload: $body,
                headers: $headers,
                bodyFormat: (string) ($endpoint['body_format'] ?? 'json'),
                beforeSending: function (Request $request) use (&$sent): void {
                    $sent = $request;
                },
            );
        } catch (ConnectionException $exception) {
            return [...$this->failure('Connection failed: '.$exception->getMessage(), $startedAt), 'request' => $this->describeRequest($sent)];
        } catch (ApiIntegrationException|InvalidArgumentException $exception) {
            return [...$this->failure($exception->getMessage(), $startedAt), 'request' => $this->describeRequest($sent)];
        }

        return [
            'success' => $response->successful(),
            'status' => $response->status(),
            'reason' => $response->reason(),
            'message' => $response->successful() ? 'The provider accepted the request.' : $this->hint($response->status()),
            'duration_ms' => $this->elapsed($startedAt),
            'request' => $this->describeRequest($sent),
            'response' => $this->describeResponse($response),
        ];
    }

    /**
     * Validate test input against the rules the generated method will use.
     *
     * @param  array<string, mixed>  $endpoint
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $body
     * @return array<string, array<int, string>>
     */
    public function validationErrors(array $endpoint, array $query, array $body): array
    {
        $rules = $this->rules->build($this->rules->inputFields([...(array) ($endpoint['query'] ?? []), ...(array) ($endpoint['body'] ?? [])]));

        if ($rules === []) {
            return [];
        }

        return Validator::make([...$query, ...$body], $rules)->errors()->toArray();
    }

    /**
     * Describe the request that was sent, with credentials masked.
     *
     * @return array<string, mixed>|null
     */
    protected function describeRequest(?Request $request): ?array
    {
        if ($request === null) {
            return null;
        }

        $url = $request->url();
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return [
            'method' => $request->method(),
            'url' => Str::before($url, '?'),
            'query' => $this->maskValues($query),
            'headers' => $this->maskValues(array_map(fn (array $values): string => implode(', ', $values), $request->headers())),
            'body' => $this->decodeBody($request->body()),
        ];
    }

    /**
     * Describe a response, truncating large bodies.
     *
     * @return array<string, mixed>
     */
    protected function describeResponse(Response $response): array
    {
        $raw = $response->body();
        $limit = (int) config('integration-builder.max_response_bytes');

        return [
            'headers' => array_map(fn (array $values): string => implode(', ', $values), $response->headers()),
            'content_type' => $response->header('Content-Type'),
            'size' => strlen($raw),
            'truncated' => strlen($raw) > $limit,
            'body' => strlen($raw) > $limit ? substr($raw, 0, $limit) : $this->decodeBody($raw),
        ];
    }

    /**
     * Decode a JSON body, falling back to the raw string.
     */
    protected function decodeBody(string $body): mixed
    {
        if ($body === '') {
            return null;
        }

        $decoded = json_decode($body, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $body;
    }

    /**
     * Mask the values of sensitive keys.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    protected function maskValues(array $values): array
    {
        foreach ($values as $name => $value) {
            if (is_scalar($value) && Str::contains(Str::lower((string) $name), self::SENSITIVE_NAMES)) {
                $values[$name] = $this->mask((string) $value);
            }
        }

        return $values;
    }

    /**
     * Mask a secret, keeping a short prefix (such as "Bearer") and the last characters.
     */
    protected function mask(string $value): string
    {
        $prefix = '';

        if (preg_match('/^(Bearer|Basic|Token)\s+(.+)$/i', $value, $matches)) {
            [$prefix, $value] = [$matches[1].' ', $matches[2]];
        }

        return $prefix.(strlen($value) <= 8 ? '••••••••' : '••••••••'.substr($value, -4));
    }

    /**
     * Explain a failed status code.
     */
    protected function hint(int $status): string
    {
        return match (true) {
            $status === 401 => 'Authentication was rejected. Check the auth type, credential columns and token settings.',
            $status === 403 => 'The credentials are valid but lack permission for this endpoint.',
            $status === 404 => 'Endpoint not found. Check the path and the provider base URL column.',
            $status === 405 => 'The provider does not allow this HTTP method on this path.',
            in_array($status, [400, 422], true) => 'The provider rejected the request data. Review the response for field errors.',
            $status === 429 => 'Rate limited by the provider. Wait before testing again.',
            $status >= 500 => 'The provider returned a server error.',
            default => 'The provider returned an unexpected status.',
        };
    }

    /**
     * Build a failed result.
     *
     * @return array<string, mixed>
     */
    protected function failure(string $message, int $startedAt): array
    {
        return ['success' => false, 'message' => $message, 'duration_ms' => $this->elapsed($startedAt)];
    }

    /**
     * Get the elapsed milliseconds since a high resolution timestamp.
     */
    protected function elapsed(int $startedAt): int
    {
        return (int) ((hrtime(true) - $startedAt) / 1_000_000);
    }
}
