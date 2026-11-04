# API Integration Builder for Laravel

Build third-party API integrations from a UI instead of by hand. Describe a provider's authentication and endpoints (or paste sample JSON / import OpenAPI), test them live, preview the code, then generate a clean Laravel controller with inline validation and routes.

The generated code is plain Laravel and reads your own credentials table or config, so it keeps working if you remove the package.

## Requirements

PHP 8.3+ and Laravel 12 or 13. Nothing else: no Inertia, no npm packages and no build step, because the builder page ships prebuilt inside the package. It works whatever your project's frontend is.

## Installation

```bash
composer require devforge/apidev --dev
php artisan apidev:install
```

Install it with `--dev`: the builder writes code into your project, so it should never run in production.

`apidev:install` creates `config/integration-builder.php`, lists your tables, suggests how their columns map to the builder's credential keys (for example `client_secret` to `secret_key`, `endpoint` to `url`) and saves the mapping. If your credentials live in `.env`, choose the config driver and it prints the `config/services.php` entries to add.

Then open `/integration-builder`. Nothing is copied into `public/` or `resources/`.

**Who can open it:** logged-in users; guests are sent to your login page. In an app without a login page it is open only when `APP_ENV=local`. To change this, set `routes.middleware` in the config, for example `['web', 'auth:admin']`.

## Uninstalling

```bash
php artisan apidev:uninstall
composer remove devforge/apidev
```

`apidev:uninstall` deletes `config/integration-builder.php`, clears the cached config and routes, and asks before deleting the builder's backups (`--force` skips the questions and keeps them; `--backups` deletes them).

Code you generated stays and keeps working, because it never depends on this package. Delete it by hand if you no longer want it.

## Troubleshooting

**`requires ext-posix` or `ext-pcntl` during install or remove (Windows).** Another package in your project, usually Laravel Horizon, needs Linux-only PHP extensions, and Composer checks every package whenever dependencies change. Add `--ignore-platform-req=ext-posix --ignore-platform-req=ext-pcntl` to the command, or tell Composer once for that project:

```bash
composer config platform.ext-posix 8.4
composer config platform.ext-pcntl 8.4
```

**`Failed to open stream ... devforge/apidev/src/IntegrationBuilderServiceProvider.php` after removing.** The removal was interrupted before the autoload files were rebuilt. Run this; it needs no working `artisan`:

```bash
composer dump-autoload
```

**The builder page returns 404.** It is off when `APP_ENV=production`. Set `INTEGRATION_BUILDER_ENABLED=true` to override this, or check `routes.prefix` if you changed the URL.

**Vue or React form pages don't compile.** Those frontends generate Inertia pages, so they need Inertia and Wayfinder in your project. Choose **Blade**, the default, for pages that need nothing extra.

## Credential sources

Every driver exposes the same keys: `url`, `base_url`, `auth_url`, `api_key`, `secret_key`, `token`, `refresh_token`, `user_name`, `password` and `tenant_id`. Set the driver in `config/integration-builder.php` or with `INTEGRATION_BUILDER_CREDENTIALS`.

| Driver     | Reads from                                         | Generated code loads values with                     |
| ---------- | -------------------------------------------------- | ---------------------------------------------------- |
| `database` | Any table, with your column names                  | Your model, or `DB::table(...)` when no model is set |
| `config`   | A config path per provider, e.g. `services.stripe` | `config('services.stripe.token')`                    |
| `custom`   | A class implementing `CredentialResolver`          | Whatever its `generatedLoader()` returns             |

The database driver also supports values inside JSON columns (`'token' => 'settings->access_token'`), encrypted columns (list the keys under `encrypted`), extra `where` conditions such as a team, and a live flag column.

```php
'database' => [
    'model' => null,
    'table' => 'partner_credentials',
    'name_column' => 'provider',
    'live' => ['column' => 'environment', 'value' => 'live'],
    'columns' => [
        'url' => 'endpoint',
        'api_key' => 'client_id',
        'secret_key' => 'client_secret',
        'token' => 'settings->access_token',
    ],
    'encrypted' => ['secret_key'],
    'where' => ['team_id' => 1],
],
```

## Authentication

Static authentication (bearer token, API key in a header or query string, HTTP Basic) is set once and applied to every request.

For APIs that issue tokens, choose **Token from an endpoint** and add the login or token endpoint like any other endpoint:

1. Tick **This endpoint provides the token** and set where the token and its expiry are in the response.
2. Set its fields to a credential (e.g. `client_id` from `api_key`) or a fixed value (e.g. `grant_type` = `client_credentials`).
3. If the token server wants the client ID and secret in a Basic header, pick the credentials to send as HTTP Basic.
4. Endpoints marked **Requires auth token** get the cached token and retry once with a fresh one when the API answers 401.

No authentication method is generated automatically.

## What gets generated

For each integration: one `{Name}ApiController` with a method per endpoint and its validation inline, plus a route group in `routes/web.php`. Per endpoint you can also opt in to a form page (read-only until **Edit**) and a model and migration that store the request and the API response.

Form pages can be generated as a standalone **Blade** view (the default, needing no JavaScript framework or CSS build), an Inertia **Vue** page or an Inertia **React** page (`.tsx`). Choose the frontend in the builder's first step.

```php
public function createCustomer(Request $request): JsonResponse
{
    $validated = $request->validate([
        'email' => ['required', 'email'],
    ]);

    $auth = $this->getAuthToken();

    if (! $auth['success']) {
        return response()->json(['success' => false, 'message' => $auth['message']], (int) ($auth['status'] ?? 500));
    }

    $payload = $validated;
    $payload['channel'] = 'api';

    $result = $this->request('POST', 'customers', $payload, $auth['token']);

    return response()->json($result, (int) ($result['status'] ?? 500));
}
```

Running the builder again for the same integration only appends missing methods and routes. Existing code, form pages and models are never overwritten.

## Security

- The builder is enabled in every environment except production (`INTEGRATION_BUILDER_ENABLED`), and writes files only when `APP_ENV=local` (`INTEGRATION_BUILDER_WRITES`).
- Logged-in users can open it; without a login page it is open only on `APP_ENV=local`. Change `routes.middleware` to restrict it further.
- Credentials are only read. The UI shows which ones are set, never their values, and test results mask secrets.
- Endpoint paths must be relative to the provider's URL, so credentials are never sent to another host.
- Every file is previewed with a hash and backed up before it changes, and generated PHP is syntax-checked before it is written.

## Configuration

| Setting              | Env variable                      | Default                                           |
| -------------------- | --------------------------------- | ------------------------------------------------- |
| `enabled`            | `INTEGRATION_BUILDER_ENABLED`     | true outside production                           |
| `routes.prefix`      | `INTEGRATION_BUILDER_PATH`        | `integration-builder`                             |
| `routes.middleware`  | —                                 | `['web', 'auth']`                                 |
| `credentials.driver` | `INTEGRATION_BUILDER_CREDENTIALS` | `database`                                        |
| `allow_file_writes`  | `INTEGRATION_BUILDER_WRITES`      | true only when `APP_ENV=local`                    |
| `backup_path`        | —                                 | `storage/app/private/integration-builder/backups` |

## Developing

```bash
composer install && vendor/bin/pest   # tests
npm install && npm run build         # rebuild the UI into dist/ and commit it
```

## License

MIT. See [LICENSE](LICENSE).
