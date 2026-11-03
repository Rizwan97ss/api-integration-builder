<?php

namespace DevForge\ApiDev\Credentials;

/**
 * Reads providers from config files, usually config/services.php backed by .env.
 *
 * Each provider names a config path whose keys match the builder's keys,
 * e.g. `services.stripe.base_url` and `services.stripe.token`.
 *
 * @phpstan-import-type GeneratedLoader from CredentialResolver
 */
class ConfigCredentialResolver implements CredentialResolver
{
    /**
     * @param  array<string, mixed>  $config  The `integration-builder.credentials.config` settings.
     */
    public function __construct(protected array $config) {}

    public function all(): array
    {
        return collect((array) ($this->config['providers'] ?? []))
            ->map(fn (mixed $provider, int|string $id): ?ProviderCredentials => is_array($provider) ? $this->toProvider((string) $id, $provider) : null)
            ->filter()
            ->sortBy(fn (ProviderCredentials $provider): string => strtolower($provider->name))
            ->values()
            ->all();
    }

    public function find(string $id): ?ProviderCredentials
    {
        $provider = $this->config['providers'][$id] ?? null;

        return is_array($provider) ? $this->toProvider($id, $provider) : null;
    }

    public function label(): string
    {
        return 'your config files';
    }

    public function generatedLoader(ProviderCredentials $provider, array $keys): array
    {
        $path = $this->path($provider->id);
        $values = [];

        foreach ($keys as $key) {
            $values[$key] = 'config('.var_export("{$path}.{$key}", true).')';
        }

        return [
            'imports' => [],
            'statements' => [],
            'values' => $values,
            'description' => "Load the {$provider->name} API details from config ({$path}).",
        ];
    }

    /**
     * Build provider credentials from a configured provider.
     *
     * @param  array<string, mixed>  $provider
     */
    protected function toProvider(string $id, array $provider): ProviderCredentials
    {
        $path = $this->path($id);
        $values = [];

        foreach ([...ProviderCredentials::URL_KEYS, ...ProviderCredentials::CREDENTIAL_KEYS] as $key) {
            $value = config("{$path}.{$key}");
            $values[$key] = is_scalar($value) ? (string) $value : null;
        }

        return new ProviderCredentials(
            id: $id,
            name: (string) ($provider['name'] ?? $id),
            isLive: (bool) ($provider['live'] ?? false),
            values: $values,
        );
    }

    /**
     * Get the config path holding a provider's keys.
     */
    protected function path(string $id): string
    {
        return (string) ($this->config['providers'][$id]['path'] ?? "services.{$id}");
    }
}
