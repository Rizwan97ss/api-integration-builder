# Changelog

All notable changes to `devforge/apikit` are documented here.

## Unreleased

### Added

- Visual builder for Laravel API integrations: provider, authentication, endpoints, review and generate.
- Endpoints from hand-written fields, pasted sample JSON or OpenAPI / Swagger documents.
- Live tests for authentication and endpoints, with credentials masked in every result.
- Generated `{Name}ApiController` with inline validation, a route group in `routes/web.php`, optional form pages, models and migrations.
- Token endpoints: mark the login or token endpoint you add; its token is cached and refreshed once on a 401.
- Field values from a credential or a fixed value, added by the generated code instead of the caller.
- Credential drivers: any database table (column mapping, JSON paths, encrypted values, scope conditions), config files, or a custom resolver.
- `php artisan apikit:install` to detect the credentials table and write the mapping.
- Form pages as Inertia Vue, Inertia React or standalone Blade views.
- HTTP Basic client authentication on the token endpoint.
- Builder routes load only when `integration-builder.enabled` is true, which defaults to every environment except production.
