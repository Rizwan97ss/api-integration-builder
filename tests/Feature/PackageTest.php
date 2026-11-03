<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Rizwan\IntegrationBuilder\Credentials\ConfigCredentialResolver;
use Rizwan\IntegrationBuilder\Credentials\CredentialResolver;
use Rizwan\IntegrationBuilder\Credentials\DatabaseCredentialResolver;
use Rizwan\IntegrationBuilder\Services\CodeGenerator;
use Rizwan\IntegrationBuilder\Services\IntegrationClient;

test('the service provider merges config, binds the resolver and registers routes and the install command', function () {
    expect(config('integration-builder.credentials.driver'))->toBe('database')
        ->and(config('integration-builder.credentials.database.model'))->toBeNull()
        ->and(app(CredentialResolver::class))->toBeInstanceOf(DatabaseCredentialResolver::class)
        ->and(Route::has('integration-builder.index'))->toBeTrue()
        ->and(Route::has('integration-builder.generate'))->toBeTrue()
        ->and(array_keys(Artisan::all()))->toContain('integration-builder:install');
});

test('the config driver is used when configured', function () {
    config()->set('integration-builder.credentials.driver', 'config');
    app()->forgetInstance(CredentialResolver::class);

    expect(app(CredentialResolver::class))->toBeInstanceOf(ConfigCredentialResolver::class);
});

test('generated controllers load credentials from the table without the package or an app model', function () {
    $provider = $this->provider(['token' => 'secret-token']);

    $plan = collect(app(CodeGenerator::class)->plan(definition($provider->id), $provider))->keyBy('path');
    $controller = $plan['app/Http/Controllers/AcmePayApiController.php']['contents'];

    PhpToken::tokenize($controller, TOKEN_PARSE);

    expect($controller)
        ->toContain("\$apiDetails = DB::table('api_keys')->where('name', 'Acme')->first() ?? throw new RuntimeException('Acme API details are missing from the api_keys table.');")
        ->toContain("\$payload['channel'] = 'api';")
        ->toContain("'email' => ['required', 'email'],")
        ->not->toContain('Rizwan\\IntegrationBuilder')
        ->not->toContain('secret-token');

    expect($plan['routes/web.php']['contents'])->toContain("Route::post('customers', 'createCustomer')->name('create-customer');");
});

test('the live client fetches a token from the token endpoint and retries once on 401', function () {
    Http::preventStrayRequests();
    $provider = $this->provider(['user_name' => 'api-user', 'password' => 'api-password']);

    Http::fake([
        'api.example.test/v1/login' => Http::sequence()->push(['token' => 'first'])->push(['token' => 'second']),
        'api.example.test/v1/customers' => Http::sequence()->push([], 401)->push(['id' => 1]),
    ]);

    $client = new IntegrationClient($provider, [
        'type' => 'token_endpoint',
        'endpoint' => [
            'method' => 'POST',
            'path' => '/login',
            'token_path' => 'token',
            'body' => [
                ['name' => 'username', 'type' => 'string', 'source' => 'credential', 'credential' => 'user_name'],
                ['name' => 'password', 'type' => 'string', 'source' => 'credential', 'credential' => 'password'],
            ],
        ],
    ]);

    expect($client->send('GET', '/customers')->json())->toBe(['id' => 1]);

    Http::assertSentCount(4);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/customers')
        && $request->hasHeader('Authorization', 'Bearer second'));
});
