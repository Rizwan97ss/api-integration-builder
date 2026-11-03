<?php

namespace Rizwan\IntegrationBuilder\Credentials;

/**
 * Reads API providers and their credentials from wherever a project keeps them.
 *
 * Implementations also describe how generated controllers load the same
 * values at runtime, so generated code reads the project's own source and
 * never depends on the builder.
 *
 * @phpstan-type GeneratedLoader array{
 *     imports: list<string>,
 *     statements: list<string>,
 *     values: array<string, string>,
 *     description: string,
 * }
 */
interface CredentialResolver
{
    /**
     * List every provider, ordered by name.
     *
     * @return array<int, ProviderCredentials>
     */
    public function all(): array;

    /**
     * Find a provider by its identifier.
     */
    public function find(string $id): ?ProviderCredentials;

    /**
     * Describe where providers come from, e.g. "the api_keys table".
     */
    public function label(): string;

    /**
     * Describe the PHP that loads a provider's values in a generated controller.
     *
     * `statements` run first in the constructor; `values` maps each requested
     * key to a PHP expression for its value.
     *
     * @param  list<string>  $keys
     * @return GeneratedLoader
     */
    public function generatedLoader(ProviderCredentials $provider, array $keys): array;
}
