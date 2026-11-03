# API Integration Builder for Laravel

Build third-party API integrations from a UI instead of by hand. Describe a provider's authentication and endpoints (or paste sample JSON / import OpenAPI), test them live, preview the code, then generate a clean Laravel controller with inline validation and routes.

The generated code is plain Laravel and reads your own credentials table or config, so it keeps working if you remove the package.

## Requirements

- PHP 8.3+ and Laravel 12 or 13
- For the builder UI: Inertia v3 with Vue 3, Tailwind CSS 4, PrimeVue 4.5 and primeicons 7, and [Wayfinder](https://github.com/laravel/wayfinder) for route helpers

## Installation

Install it as a development dependency. The builder writes code into your project, so it should never run in production:

```bash
composer require rizwan/api-integration-builder --dev
```

Publish the config, then tell the builder where your API credentials are stored:

```bash
php artisan vendor:publish --tag=integration-builder-config
php artisan integration-builder:install
```

The install command lists your tables, suggests how their columns map to the builder's credential keys (for example `client_secret` to `secret_key`, `endpoint` to `url`), detects a matching Eloquent model and writes the mapping to `config/integration-builder.php`. If your credentials live in `.env`, choose the config driver and it prints the `config/services.php` entries to add.

### Builder UI

Publish the Vue pages and components into `resources/js`, install the UI libraries and rebuild:

```bash
php artisan vendor:publish --tag=integration-builder-views
npm install primevue@^4.5 @primeuix/themes primeicons@^7 vue-sonner
npm run build
```

Register PrimeVue in `resources/js/app.ts` with the published helper:

```ts
import { installPrimeVue } from '@/lib/primevue';

// inside setup(), after creating the app:
installPrimeVue(app);
```

Then open `/integration-builder` while logged in. The page is a normal Inertia page (`integrations/Builder`), so wrap it in your own layout if you like.

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

Form pages can be generated as an Inertia **Vue** page, an Inertia **React** page (`.tsx`), or a standalone **Blade** view that needs no JavaScript framework or CSS build. Choose the frontend in the builder's first step.

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
- Its routes use the `web` and `auth` middleware by default; change `routes.middleware` to restrict them further.
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

## Testing

```bash
composer install
vendor/bin/pest
```

## License

MIT. See [LICENSE](LICENSE).
