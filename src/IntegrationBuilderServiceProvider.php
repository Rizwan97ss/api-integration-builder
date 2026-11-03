<?php

namespace Rizwan\IntegrationBuilder;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Rizwan\IntegrationBuilder\Console\InstallCommand;
use Rizwan\IntegrationBuilder\Credentials\ConfigCredentialResolver;
use Rizwan\IntegrationBuilder\Credentials\CredentialResolver;
use Rizwan\IntegrationBuilder\Credentials\DatabaseCredentialResolver;

/**
 * Registers the API Integration Builder: config, credential resolver, routes and commands.
 *
 * The builder's routes only load when `integration-builder.enabled` is true,
 * which by default is every environment except production.
 */
class IntegrationBuilderServiceProvider extends ServiceProvider
{
    /**
     * Register the package's services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/integration-builder.php', 'integration-builder');

        $this->app->singleton(CredentialResolver::class, function (Application $app): CredentialResolver {
            $config = (array) config('integration-builder.credentials');

            return match ($config['driver'] ?? 'database') {
                'database' => new DatabaseCredentialResolver((array) ($config['database'] ?? [])),
                'config' => new ConfigCredentialResolver((array) ($config['config'] ?? [])),
                default => $this->customCredentialResolver($app, $config['custom'] ?? null),
            };
        });
    }

    /**
     * Bootstrap the package: routes, commands and publishable files.
     */
    public function boot(): void
    {
        if (config('integration-builder.enabled')) {
            $this->loadRoutesFrom(__DIR__.'/../routes/integration-builder.php');
        }

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([InstallCommand::class]);

        $this->publishes([
            __DIR__.'/../config/integration-builder.php' => config_path('integration-builder.php'),
        ], 'integration-builder-config');

        $this->publishes([
            __DIR__.'/../resources/js' => resource_path('js'),
        ], 'integration-builder-views');
    }

    /**
     * Resolve the project's own credential resolver class.
     */
    protected function customCredentialResolver(Application $app, mixed $class): CredentialResolver
    {
        if (! is_string($class) || ! is_subclass_of($class, CredentialResolver::class)) {
            throw new InvalidArgumentException('Set integration-builder.credentials.custom to a class implementing '.CredentialResolver::class.'.');
        }

        return $app->make($class);
    }
}
