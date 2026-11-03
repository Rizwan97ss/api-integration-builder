<?php

namespace DevForge\IntegrationBuilder\Credentials;

use Carbon\CarbonInterface;

/**
 * One API provider and its credentials, whichever source they came from.
 *
 * Values are keyed by the builder's fixed keys, so the rest of the builder
 * never needs to know the column or config names a project uses.
 */
final class ProviderCredentials
{
    /**
     * Keys that hold credentials.
     *
     * @var list<string>
     */
    public const array CREDENTIAL_KEYS = ['api_key', 'secret_key', 'token', 'refresh_token', 'user_name', 'password', 'tenant_id'];

    /**
     * Keys that hold URLs.
     *
     * @var list<string>
     */
    public const array URL_KEYS = ['url', 'base_url', 'auth_url'];

    /**
     * @param  array<string, string|null>  $values
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly bool $isLive = false,
        protected readonly array $values = [],
        public readonly ?CarbonInterface $updatedAt = null,
    ) {}

    /**
     * Get a value, or null when it is empty.
     */
    public function get(string $key): ?string
    {
        $value = $this->values[$key] ?? null;

        return blank($value) ? null : (string) $value;
    }

    /**
     * Determine whether a key holds a value.
     */
    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * Report which credential keys hold a value, without exposing them.
     *
     * @return array<string, bool>
     */
    public function availability(): array
    {
        return collect(self::CREDENTIAL_KEYS)
            ->mapWithKeys(fn (string $key): array => [$key => $this->has($key)])
            ->all();
    }

    /**
     * Get the default key to use as the API base URL.
     */
    public function defaultBaseUrlKey(): ?string
    {
        return collect(['base_url', 'url'])->first(fn (string $key): bool => $this->has($key));
    }
}
