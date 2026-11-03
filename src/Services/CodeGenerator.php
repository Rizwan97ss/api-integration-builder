<?php

namespace DevForge\ApiDev\Services;

use DevForge\ApiDev\Credentials\CredentialResolver;
use DevForge\ApiDev\Credentials\ProviderCredentials;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Generates Laravel code for an integration definition.
 *
 * Every integration gets one `{Name}ApiController` (see
 * {@see ControllerTemplate}) with one method per endpoint that validates its
 * input inline, plus a route group in routes/web.php. Authentication is an
 * endpoint like any other: the one marked as providing the token also
 * drives the private `getAuthToken()` helper. Later endpoints are appended
 * to the same controller and route group, including hand-written
 * controllers that define `getAuthToken()` and `request()`.
 *
 * The generator only reads the filesystem; it returns a plan that is
 * previewed and then written by {@see IntegrationFileWriter}.
 *
 * @phpstan-type GeneratedFile array{
 *     path: string,
 *     kind: string,
 *     status: 'create'|'update'|'unchanged'|'skip',
 *     contents: string,
 *     original: string|null,
 *     additions: string|null,
 *     added: list<string>,
 *     existing: list<string>,
 *     requires: list<string>,
 *     notes: list<string>,
 *     hash: string,
 * }
 */
class CodeGenerator
{
    use RendersPhp;

    protected const string INDENT = '    ';

    /**
     * The routes file generated routes are added to.
     */
    public const string ROUTES_FILE = 'routes/web.php';

    public function __construct(
        protected ValidationRuleBuilder $rules,
        protected PhpFileMerger $merger,
        protected StorageSchema $storage,
        protected FrontendFormatter $formatter,
        protected CredentialResolver $credentials,
    ) {}

    /**
     * Describe what already exists for an integration name.
     *
     * @return array{studly: string, controller: string, path: string, exists: bool, generated: bool, compatible: bool, methods: list<string>}
     */
    public function inspect(string $name): array
    {
        $names = $this->names(['name' => $name]);
        $path = base_path($names['controller_path']);
        $exists = File::exists($path);
        $code = $exists ? File::get($path) : '';
        $methods = $exists ? $this->merger->methodNames($code) : [];

        return [
            'studly' => $names['studly'],
            'controller' => $names['controller'],
            'path' => $names['controller_path'],
            'exists' => $exists,
            'generated' => str_contains($code, StorageSchema::MARKER),
            'compatible' => ! $exists || array_diff(ControllerTemplate::HELPERS, $methods) === [],
            'methods' => array_values(array_filter(
                $methods,
                fn (string $method): bool => ! str_starts_with($method, '__') && ! in_array($method, ['headers', ...ControllerTemplate::HELPERS], true),
            )),
        ];
    }

    /**
     * Build the file plan for an integration definition.
     *
     * @param  array<string, mixed>  $definition
     * @return list<GeneratedFile>
     */
    public function plan(array $definition, ProviderCredentials $provider): array
    {
        $names = $this->names($definition);
        $authenticates = ($definition['auth']['type'] ?? 'none') !== 'none';
        $endpoints = array_map(fn (array $endpoint): array => $this->describeEndpoint($endpoint, $names, $authenticates), $definition['endpoints']);

        $files = [$this->controllerPlan($definition, $provider, $names, $endpoints)];

        $storages = [];

        foreach ($endpoints as $endpoint) {
            if ($endpoint['storage'] !== null && ! isset($storages[$endpoint['storage']['model_path']])) {
                $storages[$endpoint['storage']['model_path']] = $endpoint['storage'];
            }
        }

        foreach ($storages as $storage) {
            $files[] = $this->modelPlan($storage, $names);
            $files[] = $this->migrationPlan($storage);
        }

        $forms = FormPageTemplate::make($this->frontend($definition), $names, $this->rules);

        foreach ($endpoints as $endpoint) {
            if ($endpoint['create_form']) {
                $path = $forms->pagePath($endpoint);

                $files[] = $this->newFilePlan('form', $path, $this->formatter->format($forms->render($endpoint), $path), [$names['controller_path']], [
                    'Existing form pages are never overwritten.',
                ]);
            }
        }

        $files[] = $this->routesPlan($names, $endpoints, $forms);

        return $files;
    }

    /**
     * Get the frontend form pages are generated for.
     *
     * @param  array<string, mixed>  $definition
     */
    protected function frontend(array $definition): string
    {
        $frontend = (string) ($definition['options']['frontend'] ?? 'vue');

        return in_array($frontend, FormPageTemplate::FRONTENDS, true) ? $frontend : 'vue';
    }

    /**
     * Derive class, file and route names for an integration.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, string>
     */
    public function names(array $definition): array
    {
        $studly = Str::studly((string) preg_replace('/[^A-Za-z0-9]+/', ' ', (string) $definition['name']));
        $controller = "{$studly}ApiController";

        return [
            'title' => trim((string) $definition['name']),
            'studly' => $studly,
            'slug' => Str::kebab($studly),
            'controller' => $controller,
            'controller_fqcn' => "App\\Http\\Controllers\\{$controller}",
            'controller_path' => $this->existingPath("app/Http/Controllers/{$controller}.php"),
        ];
    }

    /**
     * Use the casing of an existing file when one matches case-insensitively.
     *
     * Keeps `weavrApiController.php` from being duplicated as `WeavrApiController.php`.
     */
    protected function existingPath(string $relative): string
    {
        $directory = dirname($relative);

        foreach (File::glob(base_path($directory).'/*.php') as $file) {
            if (strcasecmp(basename($file), basename($relative)) === 0) {
                return $directory.'/'.basename($file);
            }
        }

        return $relative;
    }

    /**
     * Derive method, class, path and parameter names for an endpoint.
     *
     * @param  array<string, mixed>  $endpoint
     * @param  array<string, string>  $names
     * @return array<string, mixed>
     */
    public function describeEndpoint(array $endpoint, array $names, bool $authenticates = false): array
    {
        $method = Str::camel((string) preg_replace('/[^A-Za-z0-9]+/', ' ', (string) $endpoint['name']));
        $path = '/'.ltrim((string) $endpoint['path'], '/');

        preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $path, $matches);

        $pathParams = [];

        foreach (array_unique($matches[1]) as $param) {
            $pathParams[$param] = Str::camel($param);
        }

        $query = $this->rules->inputFields((array) ($endpoint['query'] ?? []));
        $body = $this->rules->inputFields((array) ($endpoint['body'] ?? []));
        $httpMethod = strtoupper((string) $endpoint['method']);
        $providesToken = (bool) ($endpoint['provides_token'] ?? false);

        $localPath = ltrim(preg_replace_callback(
            '/\{([A-Za-z_][A-Za-z0-9_]*)\}/',
            fn (array $match): string => '{'.Str::camel($match[1]).'}',
            $path,
        ) ?? $path, '/');

        return [
            'method' => $method,
            'http_method' => $httpMethod,
            'path' => $path,
            'local_path' => $localPath === '' ? '/' : $localPath,
            'route_name' => Str::kebab($method),
            'description' => $this->sanitizeComment((string) ($endpoint['description'] ?? '')),
            'title' => Str::ucfirst(Str::lower(Str::headline($method))),
            'rules' => $this->rules->build([...$query, ...$body]),
            'path_params' => $pathParams,
            'query' => $query,
            'body' => $body,
            'query_names' => array_column($query, 'name'),
            'injected_query' => $this->rules->injectedFields((array) ($endpoint['query'] ?? [])),
            'injected_body' => $this->rules->injectedFields((array) ($endpoint['body'] ?? [])),
            'has_query' => $query !== [],
            'has_body' => $body !== [] || in_array($httpMethod, ['POST', 'PUT', 'PATCH'], true),
            'has_fields' => $query !== [] || $body !== [],
            'body_format' => (string) ($endpoint['body_format'] ?? 'json'),
            'requires_auth' => $authenticates && ! $providesToken && (bool) ($endpoint['requires_auth'] ?? true),
            'provides_token' => $providesToken,
            'token' => [
                'path' => filled($endpoint['token_path'] ?? null) ? (string) $endpoint['token_path'] : 'access_token',
                'expires_path' => filled($endpoint['expires_path'] ?? null) ? (string) $endpoint['expires_path'] : 'expires_in',
                'ttl' => filled($endpoint['token_ttl'] ?? null) ? (int) $endpoint['token_ttl'] : IntegrationClient::DEFAULT_TOKEN_TTL,
                'base_url_column' => filled($endpoint['base_url_column'] ?? null) ? (string) $endpoint['base_url_column'] : null,
                'basic_username' => filled($endpoint['basic_username'] ?? null) ? (string) $endpoint['basic_username'] : null,
                'basic_password' => filled($endpoint['basic_password'] ?? null) ? (string) $endpoint['basic_password'] : null,
            ],
            'storage' => ! $providesToken && ($endpoint['store_response'] ?? false) ? $this->describeStorage($endpoint, $names, $body) : null,
            'create_form' => ! $providesToken && (bool) ($endpoint['create_form'] ?? false),
        ];
    }

    /**
     * Describe the model and table that store an endpoint's data.
     *
     * @param  array<string, mixed>  $endpoint
     * @param  array<string, string>  $names
     * @param  array<int, mixed>  $body
     * @return array{model: string, model_fqcn: string, model_path: string, table: string, columns: list<array{column: string, field: string, method: string, arguments: list<int>, cast: string|null, doc: string}>, title: string}
     */
    protected function describeStorage(array $endpoint, array $names, array $body): array
    {
        $model = StorageSchema::modelName($endpoint, $names['studly']);

        return [
            'model' => $model,
            'model_fqcn' => "App\\Models\\{$model}",
            'model_path' => "app/Models/{$model}.php",
            'table' => StorageSchema::table($model),
            'columns' => $this->storage->columns($body),
            'title' => $this->sanitizeComment((string) $endpoint['name']),
        ];
    }

    /**
     * Plan the integration's controller, appending new methods when it exists.
     *
     * @param  array<string, mixed>  $definition
     * @param  array<string, string>  $names
     * @param  array<int, array<string, mixed>>  $endpoints
     * @return GeneratedFile
     */
    protected function controllerPlan(array $definition, ProviderCredentials $provider, array $names, array $endpoints): array
    {
        $tokenEndpoint = collect($endpoints)->firstWhere('provides_token', true);
        $template = new ControllerTemplate($definition, $provider, $names, $this->credentials, $tokenEndpoint);
        $methods = [];

        foreach ($endpoints as $endpoint) {
            $methods[$endpoint['method']] = $endpoint['provides_token']
                ? $template->tokenEndpointMethod($endpoint)
                : $template->endpointMethod($endpoint);
        }

        $endpointImports = $this->endpointImports($endpoints, $template);
        $imports = array_values(array_unique([...$template->helperImports(), ...$endpointImports]));
        usort($imports, fn (string $a, string $b): int => strcasecmp($a, $b));

        $requires = [];

        foreach ($endpoints as $endpoint) {
            if ($endpoint['storage'] !== null) {
                $requires[] = $endpoint['storage']['model_path'];
            }
        }

        $notes = ['When the controller already exists only missing methods are added; its constructor, helpers and existing methods are kept.'];

        if ($tokenEndpoint !== null) {
            $notes[] = "Its existing getAuthToken() is kept too, so update it by hand if it should now fetch the token from {$tokenEndpoint['method']}().";
        }

        return $this->classFilePlan('controller', $names['controller_path'], $template->file($methods, $imports), $methods, $endpointImports, $requires, $notes);
    }

    /**
     * List the imports the generated public methods need.
     *
     * @param  array<int, array<string, mixed>>  $endpoints
     * @return list<string>
     */
    protected function endpointImports(array $endpoints, ControllerTemplate $template): array
    {
        $imports = ['Illuminate\\Http\\JsonResponse'];

        foreach ($endpoints as $endpoint) {
            if ($endpoint['provides_token']) {
                continue;
            }

            if ($endpoint['has_query'] && $endpoint['body'] !== []) {
                $imports[] = 'Illuminate\\Support\\Arr';
            }

            if ($template->needsRequest($endpoint)) {
                $imports[] = 'Illuminate\\Http\\Request';
            }

            if ($template->idempotencyHeader() !== null && $endpoint['http_method'] !== 'GET') {
                $imports[] = 'Illuminate\\Support\\Str';
            }

            if ($endpoint['storage'] !== null) {
                $imports[] = $endpoint['storage']['model_fqcn'];
            }
        }

        $imports = array_values(array_unique($imports));
        usort($imports, fn (string $a, string $b): int => strcasecmp($a, $b));

        return $imports;
    }

    /**
     * Plan the integration's route group in routes/web.php.
     *
     * @param  array<string, string>  $names
     * @param  array<int, array<string, mixed>>  $endpoints
     * @return GeneratedFile
     */
    protected function routesPlan(array $names, array $endpoints, FormPageTemplate $forms): array
    {
        $indent = str_repeat(self::INDENT, 2);
        $routes = [];

        foreach ($endpoints as $endpoint) {
            $verb = strtolower($endpoint['http_method']);

            $routes[$endpoint['method']] = [
                'verb' => $verb,
                'uri' => $endpoint['local_path'],
                'line' => $indent."Route::{$verb}(".$this->export($endpoint['local_path']).', '.$this->export($endpoint['method'])
                    .')->name('.$this->export($endpoint['route_name']).');',
            ];

            if ($endpoint['create_form']) {
                $uri = 'forms/'.$endpoint['route_name'];

                $route = $forms->route($endpoint, $uri);

                $routes[$endpoint['method'].'Form'] = [
                    'verb' => (string) preg_replace('/^Route::(\w+)\(.*$/s', '$1', $route),
                    'uri' => $uri,
                    'line' => $indent.$route.'->name('.$this->export($endpoint['route_name'].'-page').');',
                ];
            }
        }

        $group = implode("\n", [
            "Route::middleware(['auth', 'verified'])",
            self::INDENT.'->prefix('.$this->export($names['slug']).')',
            self::INDENT.'->name('.$this->export($names['slug'].'.').')',
            self::INDENT."->controller({$names['controller']}::class)",
            self::INDENT.'->group(function () {',
            ...array_column($routes, 'line'),
            self::INDENT.'});',
        ]);

        $absolute = base_path(self::ROUTES_FILE);
        $original = File::exists($absolute) ? File::get($absolute) : "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n";
        $merged = $this->merger->addControllerRoutes($original, $names['controller'], $group, $routes);
        $contents = $merged['added'] === [] ? $original : $this->merger->addImports($merged['contents'], [$names['controller_fqcn']]);

        $added = array_intersect_key(
            array_map(fn (array $route): string => trim($route['line']), $routes),
            array_flip($merged['added']),
        );

        return $this->filePlan(
            'routes',
            self::ROUTES_FILE,
            $merged['added'] === [] ? 'unchanged' : 'update',
            $contents,
            $original,
            $added === [] ? null : implode("\n", $added),
            $merged['added'],
            $merged['existing'],
            [$names['controller_path']],
        );
    }

    /**
     * Plan a plain Eloquent model: table name, fillable columns and JSON casts.
     *
     * @param  array{model: string, model_fqcn: string, model_path: string, table: string, columns: list<array{column: string, field: string, method: string, arguments: list<int>, cast: string|null, doc: string}>, title: string}  $storage
     * @param  array<string, string>  $names
     * @return GeneratedFile
     */
    protected function modelPlan(array $storage, array $names): array
    {
        $fillable = [...array_column($storage['columns'], 'column'), 'external_id', 'response'];

        $jsonColumns = [];

        foreach ($storage['columns'] as $column) {
            if ($column['cast'] === 'array') {
                $jsonColumns[$column['column']] = 'array';
            }
        }

        $jsonColumns['response'] = 'array';

        $contents = implode("\n", [
            '<?php',
            '',
            'namespace App\\Models;',
            '',
            'use Illuminate\\Database\\Eloquent\\Model;',
            '',
            "class {$storage['model']} extends Model",
            '{',
            self::INDENT.'protected $table = '.$this->export($storage['table']).';',
            '',
            self::INDENT.'protected $fillable = '.$this->export($fillable, 1).';',
            '',
            self::INDENT.'protected $casts = '.$this->export($jsonColumns, 1).';',
            '}',
            '',
        ]);

        return $this->newFilePlan('model', $storage['model_path'], $contents, [], [
            'Existing models are never modified. Delete the file first to regenerate it.',
        ]);
    }

    /**
     * Plan the migration creating an endpoint's storage table.
     *
     * @param  array{model: string, model_fqcn: string, model_path: string, table: string, columns: list<array{column: string, field: string, method: string, arguments: list<int>, cast: string|null, doc: string}>, title: string}  $storage
     * @return GeneratedFile
     */
    protected function migrationPlan(array $storage): array
    {
        $suffix = "_create_{$storage['table']}_table.php";
        $existing = File::glob(database_path('migrations/*'.$suffix));

        if ($existing !== []) {
            $path = 'database/migrations/'.basename($existing[0]);

            return $this->filePlan('migration', $path, 'skip', File::get($existing[0]), null, null, [], [], [], [
                'A migration for this table already exists.',
            ]);
        }

        $indent = str_repeat(self::INDENT, 3);

        $columns = array_map(function (array $column) use ($indent): string {
            $arguments = implode('', array_map(fn (int $argument): string => ", {$argument}", $column['arguments']));

            return $indent."\$table->{$column['method']}(".$this->export($column['column'])."{$arguments})->nullable();";
        }, $storage['columns']);

        $contents = implode("\n", [
            '<?php',
            '',
            'use Illuminate\\Database\\Migrations\\Migration;',
            'use Illuminate\\Database\\Schema\\Blueprint;',
            'use Illuminate\\Support\\Facades\\Schema;',
            '',
            'return new class extends Migration',
            '{',
            $this->docBlock(['Run the migrations.'], 1),
            self::INDENT.'public function up(): void',
            self::INDENT.'{',
            self::INDENT.self::INDENT.'Schema::create('.$this->export($storage['table']).', function (Blueprint $table) {',
            $indent.'$table->id();',
            ...$columns,
            $indent."\$table->string('external_id')->nullable()->index();",
            $indent."\$table->json('response')->nullable();",
            $indent.'$table->timestamps();',
            self::INDENT.self::INDENT.'});',
            self::INDENT.'}',
            '',
            $this->docBlock(['Reverse the migrations.'], 1),
            self::INDENT.'public function down(): void',
            self::INDENT.'{',
            self::INDENT.self::INDENT.'Schema::dropIfExists('.$this->export($storage['table']).');',
            self::INDENT.'}',
            '};',
            '',
        ]);

        return $this->filePlan('migration', 'database/migrations/'.now()->format('Y_m_d').'_000000'.$suffix, 'create', $contents, null, null, [], [], [], [
            'Run `php artisan migrate` to create the table after approving.',
        ]);
    }

    /**
     * Plan a class file, merging new methods into an existing class.
     *
     * @param  array<string, string>  $methods
     * @param  list<string>  $imports
     * @param  list<string>  $requires
     * @param  list<string>  $notes
     * @return GeneratedFile
     */
    protected function classFilePlan(string $kind, string $path, string $fresh, array $methods, array $imports, array $requires, array $notes = []): array
    {
        if (! File::exists(base_path($path))) {
            return $this->filePlan($kind, $path, 'create', $fresh, null, null, array_keys($methods), [], $requires);
        }

        $original = File::get(base_path($path));
        $merged = $this->merger->addMethods($original, $methods);
        $contents = $merged['added'] === [] ? $original : $this->merger->addImports($merged['contents'], $imports);

        return $this->filePlan(
            $kind,
            $path,
            $merged['added'] === [] ? 'unchanged' : 'update',
            $contents,
            $original,
            $this->additions($methods, $merged['added']),
            $merged['added'],
            $merged['existing'],
            $requires,
            $notes,
        );
    }

    /**
     * Plan a file that is only ever created, never modified.
     *
     * @param  list<string>  $requires
     * @param  list<string>  $notes
     * @return GeneratedFile
     */
    protected function newFilePlan(string $kind, string $path, string $contents, array $requires = [], array $notes = []): array
    {
        if (File::exists(base_path($path))) {
            return $this->filePlan($kind, $path, 'skip', File::get(base_path($path)), null, null, [], [], $requires, $notes);
        }

        return $this->filePlan($kind, $path, 'create', $contents, null, null, [], [], $requires);
    }

    /**
     * Assemble a plan entry.
     *
     * @param  'create'|'update'|'unchanged'|'skip'  $status
     * @param  list<string>  $added
     * @param  list<string>  $existing
     * @param  list<string>  $requires
     * @param  list<string>  $notes
     * @return GeneratedFile
     */
    protected function filePlan(
        string $kind,
        string $path,
        string $status,
        string $contents,
        ?string $original,
        ?string $additions,
        array $added = [],
        array $existing = [],
        array $requires = [],
        array $notes = [],
    ): array {
        if ($existing !== []) {
            $notes[] = 'Already present and left untouched: '.implode(', ', $existing).'.';
        }

        return [
            'path' => $path,
            'kind' => $kind,
            'status' => $status,
            'contents' => $contents,
            'original' => $original,
            'additions' => $additions,
            'added' => $added,
            'existing' => $existing,
            'requires' => array_values(array_unique($requires)),
            'notes' => $notes,
            'hash' => sha1($contents),
        ];
    }

    /**
     * Join the code of the members that were added to an existing file.
     *
     * @param  array<string, string>  $code
     * @param  list<string>  $added
     */
    protected function additions(array $code, array $added): ?string
    {
        return $added === [] ? null : implode("\n\n", array_intersect_key($code, array_flip($added)));
    }
}
