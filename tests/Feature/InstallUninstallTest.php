<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    File::delete(config_path('integration-builder.php'));
    config()->set('integration-builder.backup_path', storage_path('framework/testing/apidev-backups'));
});

afterEach(function () {
    File::delete(config_path('integration-builder.php'));
    File::deleteDirectory(storage_path('framework/testing/apidev-backups'));
});

test('the prebuilt page works without inertia and never exposes credentials', function () {
    DB::table('api_keys')->insert(['name' => 'Acme', 'url' => 'https://api.example.test', 'token' => 'secret-token-value']);

    $response = $this->get(route('integration-builder.index'));

    $response->assertOk()
        ->assertSee('id="apidev-boot"', false)
        ->assertSee('"name":"Acme"', false)
        ->assertSee('"defaultFrontend":"blade"', false)
        ->assertSee('/integration-builder/assets/builder.js', false)
        ->assertDontSee('secret-token-value');
});

test('the page opens before setup when the credentials table does not exist', function () {
    Schema::drop('api_keys');

    $this->get(route('integration-builder.index'))
        ->assertOk()
        ->assertSee('"providers":[]', false);
});

test('built assets are served and nothing outside the dist folder can be read', function () {
    $this->get(route('integration-builder.asset', ['file' => 'builder.js']))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/javascript; charset=utf-8');

    $this->get('/integration-builder/assets/composer.json')->assertNotFound();
    $this->get('/integration-builder/assets/..%2Fcomposer.json')->assertNotFound();
});

test('install creates the config itself, so there is no publish step', function () {
    expect(File::exists(config_path('integration-builder.php')))->toBeFalse();

    $this->artisan('apidev:install', ['--driver' => 'database', '--table' => 'api_keys'])
        ->expectsConfirmation('Use this mapping?', 'yes')
        ->assertSuccessful();

    expect(File::get(config_path('integration-builder.php')))->toContain("'table' => 'api_keys'");
});

test('install reports an unreadable database instead of crashing', function () {
    config()->set('database.connections.broken', ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'x', 'username' => 'x', 'password' => 'x']);

    $this->artisan('apidev:install', ['--driver' => 'database', '--connection' => 'broken'])
        ->expectsOutputToContain('Could not read the database')
        ->assertFailed();
});

test('uninstall removes the config but keeps backups unless asked', function () {
    File::put(config_path('integration-builder.php'), '<?php return [];');
    File::ensureDirectoryExists(config('integration-builder.backup_path'));

    $this->artisan('apidev:uninstall', ['--force' => true])
        ->expectsOutputToContain('composer remove devforge/apidev')
        ->assertSuccessful();

    expect(File::exists(config_path('integration-builder.php')))->toBeFalse()
        ->and(File::isDirectory(config('integration-builder.backup_path')))->toBeTrue();

    $this->artisan('apidev:uninstall', ['--force' => true, '--backups' => true])->assertSuccessful();

    expect(File::isDirectory(config('integration-builder.backup_path')))->toBeFalse();
});

test('uninstall can be cancelled', function () {
    File::put(config_path('integration-builder.php'), '<?php return [];');

    $this->artisan('apidev:uninstall')
        ->expectsConfirmation('Delete config/integration-builder.php and clear the cached config and routes?', 'no')
        ->assertSuccessful();

    expect(File::exists(config_path('integration-builder.php')))->toBeTrue();
});
