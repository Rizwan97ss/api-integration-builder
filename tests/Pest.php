<?php

use DevForge\ApiDev\Tests\DisabledTestCase;
use DevForge\ApiDev\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');
pest()->extend(DisabledTestCase::class)->in('Disabled');

/**
 * Build a minimal integration definition as the builder UI submits it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function definition(string $providerId, array $overrides = []): array
{
    return array_replace([
        'provider_id' => $providerId,
        'name' => 'Acme Pay',
        'base_url_column' => 'url',
        'auth' => ['type' => 'bearer', 'token_column' => 'token'],
        'options' => ['timeout' => 15],
        'endpoints' => [[
            'name' => 'Create Customer',
            'method' => 'POST',
            'path' => '/customers',
            'body' => [
                ['name' => 'email', 'type' => 'email', 'required' => true],
                ['name' => 'channel', 'type' => 'string', 'source' => 'fixed', 'value' => 'api'],
            ],
        ]],
    ], $overrides);
}
