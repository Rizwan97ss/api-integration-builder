<?php

use DevForge\IntegrationBuilder\Http\Controllers\IntegrationBuilderController;
use Illuminate\Support\Facades\Route;

Route::middleware((array) config('integration-builder.routes.middleware', ['web', 'auth']))
    ->prefix((string) config('integration-builder.routes.prefix', 'integration-builder'))
    ->name('integration-builder.')
    ->controller(IntegrationBuilderController::class)
    ->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('import', 'import')->name('import');
        Route::get('inspect', 'inspect')->name('inspect');
        Route::post('preview', 'preview')->name('preview');
        Route::post('test-auth', 'testAuth')->middleware('throttle:30,1')->name('test-auth');
        Route::post('test-endpoint', 'testEndpoint')->middleware('throttle:30,1')->name('test-endpoint');
        Route::post('generate', 'generate')->middleware('throttle:10,1')->name('generate');
    });
