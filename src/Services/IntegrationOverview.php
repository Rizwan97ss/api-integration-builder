<?php

namespace DevForge\ApiKit\Services;

use DevForge\ApiKit\Credentials\CredentialResolver;
use DevForge\ApiKit\Credentials\ProviderCredentials;
use DevForge\ApiKit\Http\Controllers\IntegrationBuilderController;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * Summarises providers, generated integrations, stored records and backups for the dashboard.
 *
 * Integrations are discovered from the registered routes, so the dashboard always
 * reflects the controllers and routes that actually exist.
 */
class IntegrationOverview
{
    /**
     * Number of days shown in the stored records chart.
     */
    public const int ACTIVITY_DAYS = 14;

    public function __construct(protected Router $router, protected CredentialResolver $credentials) {}

    /**
     * Build every dashboard section.
     *
     * @return array{providers: array<int, array<string, mixed>>, integrations: array<int, array<string, mixed>>, storage: array<int, array<string, mixed>>, activity: array<int, array{date: string, count: int}>, recentRecords: array<int, array<string, mixed>>, backups: array<int, array{id: string, created_at: string, files: array<int, string>}>, canWriteFiles: bool, credentialSource: string}
     */
    public function summary(): array
    {
        $integrations = $this->integrations();
        $models = collect($integrations)
            ->flatMap(fn (array $integration): array => $integration['models'])
            ->unique()
            ->values()
            ->all();

        return [
            'providers' => $this->providers($integrations),
            'integrations' => $integrations,
            'storage' => $this->storage($models),
            'activity' => $this->activity($models),
            'recentRecords' => $this->recentRecords($models),
            'backups' => $this->backups(),
            'canWriteFiles' => (bool) config('integration-builder.allow_file_writes'),
            'credentialSource' => $this->credentials->label(),
        ];
    }

    /**
     * Group the registered `*ApiController` routes and their form pages by integration.
     *
     * @return array<int, array{name: string, controller: string, provider: string|null, endpoints: array<int, array{name: string|null, action: string, method: string, uri: string, form: string|null, is_auth: bool}>, models: array<int, class-string<Model>>}>
     */
    public function integrations(): array
    {
        $routes = collect($this->router->getRoutes()->getRoutes());

        $formPages = $routes
            ->filter(fn (Route $route): bool => isset($route->defaults['component']) && str_ends_with((string) $route->getName(), '-page'))
            ->mapWithKeys(fn (Route $route): array => [Str::beforeLast((string) $route->getName(), '-page') => '/'.ltrim($route->uri(), '/')]);

        return $routes
            ->filter(fn (Route $route): bool => $this->isIntegrationRoute($route))
            ->groupBy(fn (Route $route): string => (string) $route->getControllerClass())
            ->map(function (Collection $controllerRoutes, string $controller) use ($formPages): array {
                $source = $this->source($controller);

                return [
                    'name' => Str::headline(Str::beforeLast(class_basename($controller), 'ApiController')),
                    'controller' => class_basename($controller),
                    'provider' => $this->providerName($source),
                    'endpoints' => $controllerRoutes
                        ->map(fn (Route $route): array => [
                            'name' => $route->getName(),
                            'action' => $route->getActionMethod(),
                            'method' => collect($route->methods())->reject(fn (string $method): bool => $method === 'HEAD')->first() ?? 'GET',
                            'uri' => '/'.ltrim($route->uri(), '/'),
                            'form' => $formPages->get((string) $route->getName()),
                            'is_auth' => $this->isAuthMethod($controller, $route->getActionMethod()),
                        ])
                        ->sortBy(fn (array $endpoint): string => ($endpoint['is_auth'] ? '0' : '1').$endpoint['uri'])
                        ->values()
                        ->all(),
                    'models' => $this->importedModels($source),
                ];
            })
            ->sortBy('name')
            ->values()
            ->all();
    }

    /**
     * Summarise the providers from the credential source without exposing any credential.
     *
     * @param  array<int, array{provider: string|null}>  $integrations
     * @return array<int, array{id: string, name: string, is_live: bool, host: string|null, credentials: int, credentials_total: int, integrated: bool, updated_at: string|null}>
     */
    protected function providers(array $integrations): array
    {
        $integrated = collect($integrations)->pluck('provider')->filter()->map(fn (string $name): string => Str::lower($name));

        return array_map(fn (ProviderCredentials $provider): array => [
            'id' => $provider->id,
            'name' => $provider->name,
            'is_live' => $provider->isLive,
            'host' => parse_url((string) ($provider->get('base_url') ?? $provider->get('url')), PHP_URL_HOST) ?: null,
            'credentials' => count(array_filter($provider->availability())),
            'credentials_total' => count(ProviderCredentials::CREDENTIAL_KEYS),
            'integrated' => $integrated->contains(Str::lower($provider->name)),
            'updated_at' => $provider->updatedAt?->toIso8601String(),
        ], $this->credentials->all());
    }

    /**
     * Read the provider a generated controller loads, from its constructor's description.
     *
     * Older controllers are recognised by their `ApiKey::where('name', ...)` lookup.
     */
    protected function providerName(string $source): ?string
    {
        if (preg_match('/\* Load the (.+?) API details from /', $source, $match)) {
            return $match[1];
        }

        return preg_match("/ApiKey::(?:query\(\)->)?where\('name',\s*'([^']+)'\)/", $source, $match) ? $match[1] : null;
    }

    /**
     * Count the records each generated model has stored locally.
     *
     * @param  array<int, class-string<Model>>  $models
     * @return array<int, array{model: string, table: string, exists: bool, count: int, last_stored_at: string|null}>
     */
    protected function storage(array $models): array
    {
        return collect($models)
            ->map(function (string $model): array {
                $instance = new $model;
                $exists = Schema::hasTable($instance->getTable());

                return [
                    'model' => class_basename($model),
                    'table' => $instance->getTable(),
                    'exists' => $exists,
                    'count' => $exists ? $model::query()->count() : 0,
                    'last_stored_at' => $exists ? $this->toIso($model::query()->max('created_at')) : null,
                ];
            })
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * Count the records stored per day across every generated model.
     *
     * @param  array<int, class-string<Model>>  $models
     * @return array<int, array{date: string, count: int}>
     */
    protected function activity(array $models): array
    {
        $start = now()->subDays(self::ACTIVITY_DAYS - 1)->startOfDay();
        $counts = [];

        foreach ($this->existingModels($models) as $model) {
            $model::query()
                ->where('created_at', '>=', $start)
                ->get(['created_at'])
                ->each(function (Model $record) use (&$counts): void {
                    $day = Carbon::parse($record->getAttribute('created_at'))->toDateString();
                    $counts[$day] = ($counts[$day] ?? 0) + 1;
                });
        }

        return collect(range(0, self::ACTIVITY_DAYS - 1))
            ->map(function (int $offset) use ($start, $counts): array {
                $day = $start->copy()->addDays($offset)->toDateString();

                return ['date' => $day, 'count' => $counts[$day] ?? 0];
            })
            ->all();
    }

    /**
     * Get the latest records stored across every generated model.
     *
     * @param  array<int, class-string<Model>>  $models
     * @return array<int, array{model: string, id: mixed, external_id: mixed, created_at: string|null}>
     */
    protected function recentRecords(array $models, int $limit = 6): array
    {
        return collect($this->existingModels($models))
            ->flatMap(fn (string $model): Collection => $model::query()
                ->latest()
                ->limit($limit)
                ->get()
                ->map(fn (Model $record): array => [
                    'model' => class_basename($model),
                    'id' => $record->getKey(),
                    'external_id' => $record->getAttribute('external_id'),
                    'created_at' => $this->toIso($record->getAttribute('created_at')),
                ]))
            ->sortByDesc('created_at')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * List the most recent backups taken before generated code overwrote a file.
     *
     * @return array<int, array{id: string, created_at: string, files: array<int, string>}>
     */
    protected function backups(int $limit = 5): array
    {
        $path = (string) config('integration-builder.backup_path');

        if (! File::isDirectory($path)) {
            return [];
        }

        return collect(File::directories($path))
            ->map(fn (string $directory): string => basename($directory))
            ->filter(fn (string $name): bool => (bool) preg_match('/^\d{8}_\d{6}$/', $name))
            ->sortDesc()
            ->take($limit)
            ->map(fn (string $name): array => [
                'id' => $name,
                'created_at' => Carbon::createFromFormat('Ymd_His', $name)?->toIso8601String() ?? '',
                'files' => collect(File::allFiles($path.'/'.$name))
                    ->map(fn ($file): string => str_replace('\\', '/', $file->getRelativePathname()))
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Determine whether a route calls a generated integration controller.
     */
    protected function isIntegrationRoute(Route $route): bool
    {
        $controller = $route->getControllerClass();

        return $controller !== null
            && $controller !== IntegrationBuilderController::class
            && str_ends_with($controller, 'ApiController')
            && class_exists($controller);
    }

    /**
     * Read a controller's source file.
     */
    protected function source(string $controller): string
    {
        if (! class_exists($controller)) {
            return '';
        }

        try {
            return (string) File::get((string) (new ReflectionClass($controller))->getFileName());
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Determine whether a controller method only fetches the auth token.
     *
     * Token methods call `getAuthToken(true)` and never `request()`; older
     * generated controllers used a method named `authenticate`.
     */
    protected function isAuthMethod(string $controller, string $method): bool
    {
        if ($method === 'authenticate') {
            return true;
        }

        try {
            $reflection = new ReflectionMethod($controller, $method);
            $lines = array_slice(File::lines((string) $reflection->getFileName())->all(), (int) $reflection->getStartLine() - 1, (int) $reflection->getEndLine() - (int) $reflection->getStartLine() + 1);
        } catch (Throwable) {
            return false;
        }

        $body = implode("\n", $lines);

        return str_contains($body, '$this->getAuthToken(true)') && ! str_contains($body, '$this->request(');
    }

    /**
     * Get the Eloquent models a controller imports, other than the credentials model.
     *
     * @return array<int, class-string<Model>>
     */
    protected function importedModels(string $source): array
    {
        preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+);/m', $source, $matches);

        return collect($matches[1])
            ->filter(fn (string $class): bool => $class !== config('integration-builder.credentials.database.model') && class_exists($class) && is_subclass_of($class, Model::class))
            ->values()
            ->all();
    }

    /**
     * Keep only the models whose table exists.
     *
     * @param  array<int, class-string<Model>>  $models
     * @return array<int, class-string<Model>>
     */
    protected function existingModels(array $models): array
    {
        return array_values(array_filter($models, fn (string $model): bool => Schema::hasTable((new $model)->getTable())));
    }

    /**
     * Convert a stored timestamp to ISO 8601.
     */
    protected function toIso(mixed $value): ?string
    {
        return $value === null ? null : Carbon::parse($value)->toIso8601String();
    }
}
