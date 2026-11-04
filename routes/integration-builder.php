<?php

use DevForge\ApiDev\Http\Controllers\IntegrationBuilderController;
use DevForge\ApiDev\Http\Middleware\AuthorizeBuilder;
use Illuminate\Support\Facades\Route;

Route::middleware((array) config('integration-builder.routes.middleware', ['web', AuthorizeBuilder::class]))
    ->prefix((string) config('integration-builder.routes.prefix', 'integration-builder'))
    ->name('integration-builder.')
    ->controller(IntegrationBuilderController::class)
    ->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('assets/{file}', 'asset')->where('file', '[A-Za-z0-9._\-]+')->name('asset');
        Route::post('import', 'import')->name('import');
        Route::get('inspect', 'inspect')->name('inspect');
        Route::post('preview', 'preview')->name('preview');
        Route::post('test-auth', 'testAuth')->middleware('throttle:30,1')->name('test-auth');
        Route::post('test-endpoint', 'testEndpoint')->middleware('throttle:30,1')->name('test-endpoint');
        Route::post('generate', 'generate')->middleware('throttle:10,1')->name('generate');
    });
