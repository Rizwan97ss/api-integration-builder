<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enable The Builder
    |--------------------------------------------------------------------------
    |
    | The builder writes code and calls live APIs, so its routes only load
    | outside production unless INTEGRATION_BUILDER_ENABLED says otherwise.
    | Generated controllers never depend on the builder.
    |
    */

    'enabled' => (bool) env('INTEGRATION_BUILDER_ENABLED', env('APP_ENV', 'production') !== 'production'),

    'routes' => [
        'prefix' => env('INTEGRATION_BUILDER_PATH', 'integration-builder'),
        'middleware' => ['web', 'auth'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Credential Source
    |--------------------------------------------------------------------------
    |
    | Where API providers and their credentials come from. The builder only
    | reads them, and generated controllers read them the same way at runtime.
    |
    | Every driver exposes the same keys: the URL keys "url", "base_url" and
    | "auth_url", and the credential keys "api_key", "secret_key", "token",
    | "refresh_token", "user_name", "password" and "tenant_id".
    |
    | Drivers: "database" (any table, mapped below), "config" (values from a
    | config file such as config/services.php, usually backed by .env), or
    | "custom" (a class implementing DevForge\IntegrationBuilder\Credentials\CredentialResolver).
    |
    */

    'credentials' => [

        'driver' => env('INTEGRATION_BUILDER_CREDENTIALS', 'database'),

        'database' => [
            'connection' => env('INTEGRATION_BUILDER_CREDENTIALS_CONNECTION'),

            // Set a model to have generated code load providers through it, or
            // leave it null to use the table through the query builder.
            'model' => null,
            'table' => 'api_keys',

            'id_column' => 'id',
            'name_column' => 'name',
            'updated_at_column' => 'updated_at',

            // The column (and value) marking a provider as live, or null.
            'live' => ['column' => 'is_live', 'value' => 1],

            // Key => column. Use "column->path" for a value inside a JSON column.
            'columns' => [
                'url' => 'url',
                'base_url' => 'base_url',
                'auth_url' => 'auth_url',
                'api_key' => 'api_key',
                'secret_key' => 'secret_key',
                'token' => 'token',
                'refresh_token' => 'refresh_token',
                'user_name' => 'user_name',
                'password' => 'password',
                'tenant_id' => 'tenant_id',
            ],

            // Keys whose column holds a value encrypted with Crypt::encryptString().
            'encrypted' => [],

            // Extra conditions every provider query applies, e.g. ['team_id' => 1].
            'where' => [],
        ],

        'config' => [
            // Provider key => its name and the config path holding its keys, e.g.
            // 'stripe' => ['name' => 'Stripe', 'path' => 'services.stripe', 'live' => false].
            'providers' => [],
        ],

        'custom' => env('INTEGRATION_BUILDER_CREDENTIALS_RESOLVER'),

    ],

    /*
    |--------------------------------------------------------------------------
    | Allow File Generation
    |--------------------------------------------------------------------------
    |
    | Writing PHP files from a web request is only safe on a developer machine.
    | By default the builder may preview code everywhere but only writes files
    | when the application runs locally. Set INTEGRATION_BUILDER_WRITES to
    | override this explicitly.
    |
    */

    'allow_file_writes' => (bool) env('INTEGRATION_BUILDER_WRITES', env('APP_ENV', 'production') === 'local'),

    /*
    |--------------------------------------------------------------------------
    | Backup Directory
    |--------------------------------------------------------------------------
    |
    | Existing files are copied here before generated methods are merged into
    | them, so any change can be rolled back by hand.
    |
    */

    'backup_path' => storage_path('app/private/integration-builder/backups'),

    /*
    |--------------------------------------------------------------------------
    | Log Channel
    |--------------------------------------------------------------------------
    |
    | The log channel used by generated integrations for request logging.
    | Leave empty to use the application's default channel.
    |
    */

    'log_channel' => env('INTEGRATION_LOG_CHANNEL'),

    /*
    |--------------------------------------------------------------------------
    | Tester Limits
    |--------------------------------------------------------------------------
    */

    'test_timeout' => 20,

    'max_response_bytes' => 512 * 1024,

];
