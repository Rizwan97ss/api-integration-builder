<?php

namespace DevForge\IntegrationBuilder\Console;

use DevForge\IntegrationBuilder\Credentials\CredentialConfigWriter;
use DevForge\IntegrationBuilder\Credentials\CredentialMappingSuggester;
use DevForge\IntegrationBuilder\Credentials\ProviderCredentials;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\select;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

#[Signature('integration-builder:install
    {--driver= : Where credentials are stored: database or config}
    {--connection= : The database connection holding the credentials table}
    {--table= : The table holding provider credentials}
    {--model= : An Eloquent model for that table}
    {--provider= : For the config driver, the provider key to add, e.g. stripe}')]
#[Description('Tell the API Integration Builder where your API credentials are stored')]
class InstallCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(CredentialMappingSuggester $suggester, CredentialConfigWriter $writer): int
    {
        intro('API Integration Builder setup');

        $driver = $this->option('driver') ?? select(
            label: 'Where are your API credentials stored?',
            options: ['database' => 'In a database table', 'config' => 'In .env / config files'],
            default: (string) config('integration-builder.credentials.driver', 'database'),
        );

        $current = (array) config('integration-builder.credentials');

        [$database, $config] = match ($driver) {
            'database' => [$this->database($suggester), (array) ($current['config'] ?? [])],
            'config' => [(array) ($current['database'] ?? []), $this->configProviders((array) ($current['config'] ?? []))],
            default => [null, null],
        };

        if ($database === null || $config === null) {
            error("Unknown driver \"{$driver}\". Use database or config.");

            return self::FAILURE;
        }

        try {
            $backup = $writer->write((string) $driver, $database, $config);
        } catch (Throwable $exception) {
            error($exception->getMessage());

            return self::FAILURE;
        }

        info('Saved the credential settings to config/integration-builder.php.');
        note("The previous file was backed up to {$backup}. INTEGRATION_BUILDER_CREDENTIALS in .env, if set, still overrides the driver.");

        outro('Open /integration-builder to build your first integration.');

        return self::SUCCESS;
    }

    /**
     * Ask which table holds the credentials and how its columns map to the builder's keys.
     *
     * @return array<string, mixed>|null
     */
    protected function database(CredentialMappingSuggester $suggester): ?array
    {
        $connection = $this->option('connection') ?: null;
        $tables = $suggester->tables($connection);

        if ($tables === []) {
            error('The database has no tables. Run your migrations first, or choose the config driver.');

            return null;
        }

        $table = $this->option('table') ?? select(
            label: 'Which table holds your API providers?',
            options: $tables,
            default: collect($tables)->first(fn (string $name): bool => (bool) preg_match('/api|key|credential|integration|provider/i', $name)) ?? $tables[0],
            scroll: 10,
        );

        if (! in_array($table, $tables, true)) {
            error("The table \"{$table}\" does not exist.");

            return null;
        }

        $columns = $suggester->columns((string) $table, $connection);
        $settings = $suggester->suggest((string) $table, $columns);

        if (filled($this->option('model'))) {
            $settings['model'] = ltrim((string) $this->option('model'), '\\');
        } elseif ($settings['model'] !== null && ! confirm("Load providers through {$settings['model']}?", default: true)) {
            $settings['model'] = null;
        }

        $this->showMapping($settings);

        if (! confirm('Use this mapping?', default: true)) {
            $settings = $this->editMapping($settings, $columns);
            $this->showMapping($settings);
        }

        if (! isset($settings['columns']['url']) && ! isset($settings['columns']['base_url'])) {
            warning('No column holds the API URL; endpoints will need full paths until you map url or base_url.');
        }

        return ['connection' => $connection, ...$settings];
    }

    /**
     * Ask for a provider stored in config files and show the entries it reads.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function configProviders(array $config): array
    {
        $key = $this->option('provider') ?? text(
            label: 'Provider key',
            placeholder: 'stripe',
            default: 'example',
            validate: fn (string $value): ?string => preg_match('/^[a-z][a-z0-9_]*$/', $value) ? null : 'Use lowercase letters, numbers and underscores.',
            hint: 'Its values are read from config/services.php under this key.',
        );

        $name = text(label: 'Display name', default: Str::headline((string) $key));
        $path = "services.{$key}";

        $config['providers'] = [
            ...(array) ($config['providers'] ?? []),
            (string) $key => ['name' => $name, 'path' => $path, 'live' => false],
        ];

        $env = Str::upper((string) $key);

        note(implode("\n", [
            "Add these entries to config/services.php under '{$key}':",
            '',
            ...array_map(
                fn (string $entry): string => "    '{$entry}' => env('{$env}_".Str::upper($entry)."'),",
                ['base_url', 'auth_url', ...ProviderCredentials::CREDENTIAL_KEYS],
            ),
            '',
            "Then set the ones you use in .env, e.g. {$env}_BASE_URL and {$env}_TOKEN.",
        ]));

        return $config;
    }

    /**
     * Show the suggested mapping as a table.
     *
     * @param  array<string, mixed>  $settings
     */
    protected function showMapping(array $settings): void
    {
        $rows = [
            ['provider name', (string) $settings['name_column']],
            ['live flag', is_array($settings['live']) ? "{$settings['live']['column']} = {$settings['live']['value']}" : '—'],
        ];

        foreach ([...ProviderCredentials::URL_KEYS, ...ProviderCredentials::CREDENTIAL_KEYS] as $key) {
            $rows[] = [$key, (string) ($settings['columns'][$key] ?? '—')];
        }

        table(['Builder key', 'Column in '.$settings['table']], $rows);
    }

    /**
     * Let the user pick the column for each key.
     *
     * @param  array<string, mixed>  $settings
     * @param  list<string>  $columns
     * @return array<string, mixed>
     */
    protected function editMapping(array $settings, array $columns): array
    {
        $options = ['' => '— not stored —', ...array_combine($columns, $columns)];

        $settings['name_column'] = select('Which column holds the provider name?', array_combine($columns, $columns), default: $settings['name_column']);

        foreach ([...ProviderCredentials::URL_KEYS, ...ProviderCredentials::CREDENTIAL_KEYS] as $key) {
            $column = select("Column for {$key}", $options, default: $settings['columns'][$key] ?? '', scroll: 10);

            if ($column === '') {
                unset($settings['columns'][$key]);
            } else {
                $settings['columns'][$key] = $column;
            }
        }

        return $settings;
    }
}
