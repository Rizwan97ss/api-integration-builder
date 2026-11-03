<?php

namespace DevForge\ApiKit\Exceptions;

use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use Throwable;

/**
 * Raised when a call to a third-party API fails.
 */
class ApiIntegrationException extends RuntimeException
{
    /**
     * Upstream statuses that are forwarded to the caller unchanged.
     *
     * Authentication failures upstream are a server-side configuration problem,
     * so they are reported as a bad gateway instead of leaking as 401 / 403.
     *
     * @var list<int>
     */
    protected const array FORWARDED_STATUSES = [400, 404, 409, 410, 422, 429];

    /**
     * @param  array<mixed>|string|null  $responseBody
     */
    public function __construct(
        string $message,
        public readonly string $integration,
        public readonly ?int $upstreamStatus = null,
        public readonly array|string|null $responseBody = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $upstreamStatus ?? 0, $previous);
    }

    /**
     * Create an exception for an unsuccessful upstream response.
     */
    public static function fromResponse(string $integration, Response $response): self
    {
        return new self(
            sprintf('%s responded with HTTP %d.', $integration, $response->status()),
            $integration,
            $response->status(),
            $response->json() ?? mb_strimwidth($response->body(), 0, 2000, '…'),
        );
    }

    /**
     * Create an exception for a request that never received a response.
     */
    public static function connectionFailed(string $integration, Throwable $previous): self
    {
        return new self(
            sprintf('Could not connect to %s: %s', $integration, $previous->getMessage()),
            $integration,
            previous: $previous,
        );
    }

    /**
     * Create an exception for a provider that cannot be authenticated.
     */
    public static function authenticationFailed(string $integration, string $reason): self
    {
        return new self(sprintf('Authentication with %s failed: %s', $integration, $reason), $integration);
    }

    /**
     * Get the HTTP status returned to the application's own caller.
     */
    public function status(): int
    {
        return in_array($this->upstreamStatus, self::FORWARDED_STATUSES, true) ? $this->upstreamStatus : 502;
    }

    /**
     * Get the exception's context information for logging.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
            'integration' => $this->integration,
            'upstream_status' => $this->upstreamStatus,
        ];
    }

    /**
     * Render the exception into a JSON response.
     */
    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'upstream_status' => $this->upstreamStatus,
            'errors' => is_array($this->responseBody) ? $this->responseBody : null,
        ], $this->status());
    }
}
