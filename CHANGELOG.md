# Changelog

All notable changes to `devforge/apidev` are documented here.

## 0.2.2

### Fixed

- `php artisan` crashed on Laravel 12 with "cannot have an empty name", because the commands used attributes that only exist in Laravel 13.

## 0.2.1

### Added

- The builder page now ships prebuilt and is served by the package, so it works in any Laravel project with no npm install, build step or copied files.
- `php artisan apidev:uninstall` removes the config and cached files before `composer remove`.
- `AuthorizeBuilder` middleware, so apps without a login page get a working builder locally instead of a "Route [login] not defined" error.

### Changed

- `php artisan apidev:install` creates the config itself; there is no separate publish step.
- Inertia is optional. Set `integration-builder.ui` to `inertia` to render your own page.
- Blade is the default form page frontend for the prebuilt page.

### Fixed

- Opening the builder before setup no longer fails when the credentials table does not exist.
- `apidev:install` reports an unreachable database instead of crashing.

### Removed

- The `integration-builder-views` publish tag.

## 0.1.0

### Added

- Visual builder for Laravel API integrations: provider, authentication, endpoints, review and generate.
- Endpoints from hand-written fields, pasted sample JSON or OpenAPI / Swagger documents.
- Live tests for authentication and endpoints, with credentials masked in every result.
- Generated `{Name}ApiController` with inline validation, a route group in `routes/web.php`, optional form pages, models and migrations.
- Token endpoints: mark the login or token endpoint you add; its token is cached and refreshed once on a 401.
- Field values from a credential or a fixed value, added by the generated code instead of the caller.
- Credential drivers: any database table (column mapping, JSON paths, encrypted values, scope conditions), config files, or a custom resolver.
- `php artisan apidev:install` to detect the credentials table and write the mapping.
- Form pages as Inertia Vue, Inertia React or standalone Blade views.
- HTTP Basic client authentication on the token endpoint.
- Builder routes load only when `integration-builder.enabled` is true, which defaults to every environment except production.
