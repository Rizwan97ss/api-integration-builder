<?php

namespace Rizwan\IntegrationBuilder\Credentials;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Reads providers from any database table, mapping the builder's keys to its columns.
 *
 * Columns may point inside a JSON column with `column->path`, and values
 * stored with `Crypt::encryptString()` are decrypted when listed as encrypted.
 *
 * @phpstan-import-type GeneratedLoader from CredentialResolver
 */
class DatabaseCredentialResolver implements CredentialResolver
{
    /**
     * @param  array<string, mixed>  $config  The `integration-builder.credentials.database` settings.
     */
    public function __construct(protected array $config) {}

    public function all(): array
    {
        return $this->query()
            ->orderBy($this->nameColumn())
            ->get()
            ->map(fn (object $row): ProviderCredentials => $this->toProvider((array) $row))
            ->values()
            ->all();
    }

    public function find(string $id): ?ProviderCredentials
    {
        $row = $this->query()->where($this->idColumn(), $id)->first();

        return $row === null ? null : $this->toProvider((array) $row);
    }

    public function label(): string
    {
        return "the {$this->table()} table";
    }

    public function generatedLoader(ProviderCredentials $provider, array $keys): array
    {
        $model = $this->model();
        $name = var_export($provider->name, true);
        $conditions = '';

        foreach ((array) ($this->config['where'] ?? []) as $column => $value) {
            $conditions .= '->where('.var_export((string) $column, true).', '.var_export($value, true).')';
        }

        $where = '->where('.var_export($this->nameColumn(), true).", {$name}){$conditions}";

        if ($model !== null) {
            $imports = [$model];
            $statement = '$apiDetails = '.class_basename($model)."::query(){$where}->firstOrFail();";
        } else {
            $connection = filled($this->config['connection'] ?? null) ? 'connection('.var_export((string) $this->config['connection'], true).')->' : '';
            $missing = var_export("{$provider->name} API details are missing from {$this->label()}.", true);
            $imports = ['Illuminate\\Support\\Facades\\DB', 'RuntimeException'];
            $statement = "\$apiDetails = DB::{$connection}table(".var_export($this->table(), true)."){$where}->first() ?? throw new RuntimeException({$missing});";
        }

        $values = [];

        foreach ($keys as $key) {
            $values[$key] = $this->valueExpression($key);

            if ($this->isEncrypted($key) && $this->column($key) !== null) {
                $imports[] = 'Illuminate\\Support\\Facades\\Crypt';
            }
        }

        return [
            'imports' => array_values(array_unique($imports)),
            'statements' => [$statement],
            'values' => $values,
            'description' => "Load the {$provider->name} API details from {$this->label()}.",
        ];
    }

    /**
     * Start a query for providers, applying the configured conditions.
     */
    protected function query(): Builder
    {
        $query = DB::connection($this->config['connection'] ?? null)->table($this->table());

        foreach ((array) ($this->config['where'] ?? []) as $column => $value) {
            $query->where((string) $column, $value);
        }

        return $query;
    }

    /**
     * Convert a table row into provider credentials.
     *
     * @param  array<string, mixed>  $row
     */
    protected function toProvider(array $row): ProviderCredentials
    {
        $values = [];

        foreach ([...ProviderCredentials::URL_KEYS, ...ProviderCredentials::CREDENTIAL_KEYS] as $key) {
            $values[$key] = $this->readValue($row, $key);
        }

        $live = $this->config['live'] ?? null;
        $updatedAt = $row[$this->config['updated_at_column'] ?? 'updated_at'] ?? null;

        return new ProviderCredentials(
            id: (string) ($row[$this->idColumn()] ?? ''),
            name: (string) ($row[$this->nameColumn()] ?? ''),
            isLive: is_array($live) && isset($live['column']) && ($row[$live['column']] ?? null) == ($live['value'] ?? 1),
            values: $values,
            updatedAt: blank($updatedAt) ? null : Carbon::parse((string) $updatedAt),
        );
    }

    /**
     * Read one key's value from a row, following JSON paths and decrypting when configured.
     *
     * @param  array<string, mixed>  $row
     */
    protected function readValue(array $row, string $key): ?string
    {
        $column = $this->column($key);

        if ($column === null) {
            return null;
        }

        [$name, $path] = $this->splitColumn($column);
        $value = $row[$name] ?? null;

        if ($path !== null) {
            $value = data_get(is_string($value) ? json_decode($value, true) : $value, $path);
        }

        if (blank($value) || ! is_scalar($value)) {
            return null;
        }

        if (! $this->isEncrypted($key)) {
            return (string) $value;
        }

        try {
            return Crypt::decryptString((string) $value);
        } catch (Throwable) {
            throw new InvalidArgumentException("The \"{$key}\" value in {$this->table()} could not be decrypted with this application's key.");
        }
    }

    /**
     * Render the PHP expression reading one key in a generated controller.
     */
    protected function valueExpression(string $key): string
    {
        $column = $this->column($key);

        if ($column === null) {
            return 'null';
        }

        [$name, $path] = $this->splitColumn($column);
        $property = "\$apiDetails->{$name}";

        $expression = $path === null
            ? $property
            : "data_get(is_string({$property}) ? json_decode({$property}, true) : {$property}, ".var_export($path, true).')';

        return $this->isEncrypted($key)
            ? "filled({$expression}) ? Crypt::decryptString({$expression}) : null"
            : $expression;
    }

    /**
     * Get the column mapped to a key, or null when it is not mapped.
     */
    protected function column(string $key): ?string
    {
        $column = $this->config['columns'][$key] ?? null;

        return blank($column) ? null : (string) $column;
    }

    /**
     * Split `column->path` into the column and a dot path.
     *
     * @return array{0: string, 1: string|null}
     */
    protected function splitColumn(string $column): array
    {
        if (! str_contains($column, '->')) {
            return [$column, null];
        }

        return [Str::before($column, '->'), str_replace('->', '.', Str::after($column, '->'))];
    }

    protected function isEncrypted(string $key): bool
    {
        return in_array($key, (array) ($this->config['encrypted'] ?? []), true);
    }

    protected function table(): string
    {
        $model = $this->model();

        return $model !== null ? (new $model)->getTable() : (string) ($this->config['table'] ?? 'api_keys');
    }

    /**
     * Get the configured model class, if any.
     *
     * @return class-string<Model>|null
     */
    protected function model(): ?string
    {
        $model = $this->config['model'] ?? null;

        return is_string($model) && is_subclass_of($model, Model::class) ? $model : null;
    }

    protected function idColumn(): string
    {
        return (string) ($this->config['id_column'] ?? 'id');
    }

    protected function nameColumn(): string
    {
        return (string) ($this->config['name_column'] ?? 'name');
    }
}
