<?php

use Illuminate\Support\Facades\Route;

test('the builder routes are not registered when the builder is disabled', function () {
    expect(Route::has('integration-builder.index'))->toBeFalse();

    $this->get('/integration-builder')->assertNotFound();
});
