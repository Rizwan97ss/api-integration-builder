<?php

namespace DevForge\ApiKit\Credentials;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Suggests how a table's columns map to the builder's credential keys.
 *
 * Suggestions come from common column names, so `client_secret` maps to
 * `secret_key` and `endpoint` to `url`. Each column is used at most once.
 */
class CredentialMappingSuggester
{
    /**
     * Column names that usually hold each key, most likely first.
     *
     * @var array<string, list<string>>
     */
    public const array CANDIDATES = [
        'base_url' => ['base_url', 'base_uri', 'api_base_url', 'api_url'],
        'url' => ['url', 'endpoint', 'host', 'api_endpoint', 'uri'],
        'auth_url' => ['auth_url', 'token_url', 'oauth_url', 'login_url', 'auth_endpoint', 'token_endpoint'],
        'api_key' => ['api_key', 'apikey', 'key', 'client_id', 'public_key', 'app_key', 'access_key', 'access_key_id'],
        'secret_key' => ['secret_key', 'secret', 'client_secret', 'api_secret', 'private_key', 'app_secret', 'secret_access_key'],
        'token' => ['token', 'access_token', 'bearer_token', 'api_token', 'auth_token'],
        'refresh_token' => ['refresh_token'],
        'user_name' => ['user_name', 'username', 'user', 'login', 'email'],
        'password' => ['password', 'pass', 'api_password', 'passwd'],
        'tenant_id' => ['tenant_id', 'account_id', 'merchant_id', 'organization_id', 'org_id', 'company_id', 'client_ref'],
    ];

    /**
     * Column names that usually identify a provider, most likely first.
     *
     * @var list<string>
     */
    public const array NAME_CANDIDATES = ['name', 'provider', 'label', 'title', 'service', 'slug', 'code'];

    /**
     * Column names that usually mark a live provider, with the value meaning "live".
     *
     * @var array<string, int|string>
     */
    public const array LIVE_CANDIDATES = [
        'is_live' => 1,
        'live' => 1,
        'is_production' => 1,
        'production' => 1,
        'environment' => 'live',
        'env' => 'live',
        'mode' => 'live',
    ];

    /**
     * List the tables in the default connection, or the given one.
     *
     * @return array<int, string>
     */
    public function tables(?string $connection = null): array
    {
        return collect(Schema::connection($connection)->getTables())
            ->pluck('name')
            ->map(fn (mixed $name): string => (string) $name)
            ->sort()
            ->values()
            ->all();
    }

    /**
     * List a table's columns.
     *
     * @return list<string>
     */
    public function columns(string $table, ?string $connection = null): array
    {
        return Schema::connection($connection)->getColumnListing($table);
    }

    /**
     * Suggest the database driver settings for a table.
     *
     * @param  list<string>  $columns
     * @return array{model: class-string<Model>|null, table: string, id_column: string, name_column: string, updated_at_column: string|null, live: array{column: string, value: int|string}|null, columns: array<string, string>, encrypted: list<string>, where: array<string, mixed>}
     */
    public function suggest(string $table, array $columns): array
    {
        $byName = collect($columns)->mapWithKeys(fn (string $column): array => [$this->normalize($column) => $column]);
        $used = [];
        $mapping = [];

        foreach (self::CANDIDATES as $key => $candidates) {
            foreach ($candidates as $candidate) {
                $column = $byName[$this->normalize($candidate)] ?? null;

                if ($column !== null && ! in_array($column, $used, true)) {
                    $mapping[$key] = $column;
                    $used[] = $column;

                    break;
                }
            }
        }

        $live = null;

        foreach (self::LIVE_CANDIDATES as $candidate => $value) {
            if (isset($byName[$this->normalize($candidate)])) {
                $live = ['column' => (string) $byName[$this->normalize($candidate)], 'value' => $value];

                break;
            }
        }

        return [
            'model' => $this->modelFor($table),
            'table' => $table,
            'id_column' => in_array('id', $columns, true) ? 'id' : ($columns[0] ?? 'id'),
            'name_column' => $this->firstMatch($byName->all(), self::NAME_CANDIDATES) ?? ($columns[0] ?? 'name'),
            'updated_at_column' => in_array('updated_at', $columns, true) ? 'updated_at' : null,
            'live' => $live,
            'columns' => $mapping,
            'encrypted' => [],
            'where' => [],
        ];
    }

    /**
     * Find an Eloquent model in app/Models that uses the table.
     *
     * @return class-string<Model>|null
     */
    public function modelFor(string $table): ?string
    {
        foreach (File::glob(app_path('Models/*.php')) as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');

            if (class_exists($class) && is_subclass_of($class, Model::class) && (new $class)->getTable() === $table) {
                return $class;
            }
        }

        return null;
    }

    /**
     * Find the first candidate present among the normalized columns.
     *
     * @param  array<string, string>  $byName
     * @param  list<string>  $candidates
     */
    protected function firstMatch(array $byName, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (isset($byName[$this->normalize($candidate)])) {
                return $byName[$this->normalize($candidate)];
            }
        }

        return null;
    }

    /**
     * Normalize a column name so `clientSecret`, `client-secret` and `client_secret` match.
     */
    protected function normalize(string $column): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', Str::lower($column));
    }
}
