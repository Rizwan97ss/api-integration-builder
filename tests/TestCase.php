<?php

namespace DevForge\ApiKit\Tests;

use DevForge\ApiKit\Credentials\CredentialResolver;
use DevForge\ApiKit\Credentials\ProviderCredentials;
use DevForge\ApiKit\IntegrationBuilderServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [IntegrationBuilderServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('integration-builder.enabled', true);
        $app['config']->set('integration-builder.routes.middleware', ['web']);
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('url')->nullable();
            $table->string('base_url')->nullable();
            $table->string('auth_url')->nullable();
            $table->string('api_key')->nullable();
            $table->string('secret_key')->nullable();
            $table->text('token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->string('user_name')->nullable();
            $table->string('password')->nullable();
            $table->string('tenant_id')->nullable();
            $table->boolean('is_live')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Insert a provider row and load it through the resolver.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function provider(array $attributes = []): ProviderCredentials
    {
        $id = DB::table('api_keys')->insertGetId([
            'name' => 'Acme',
            'url' => 'https://api.example.test/v1',
            ...$attributes,
        ]);

        return app(CredentialResolver::class)->find((string) $id) ?? throw new \RuntimeException('Provider not found.');
    }
}
