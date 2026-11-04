<?php

use DevForge\ApiDev\Http\Middleware\AuthorizeBuilder;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Run the middleware for a request and return the response.
 */
function authorizeBuilder(?GenericUser $user = null, bool $json = false): Response
{
    $request = Request::create('/integration-builder');

    if ($json) {
        $request->headers->set('Accept', 'application/json');
    }

    $request->setUserResolver(fn (): ?GenericUser => $user);

    return (new AuthorizeBuilder)->handle($request, fn (): Response => new Response('builder'));
}

test('logged-in users get in', function () {
    expect(authorizeBuilder(new GenericUser(['id' => 1]))->getContent())->toBe('builder');
});

test('guests in a local app without a login page get in instead of a missing route error', function () {
    app()->detectEnvironment(fn (): string => 'local');

    expect(authorizeBuilder()->getContent())->toBe('builder');
});

test('guests are refused outside local when there is no login page', function () {
    app()->detectEnvironment(fn (): string => 'staging');

    expect(fn () => authorizeBuilder())->toThrow(HttpException::class, 'only open on APP_ENV=local');
});

test('guests go to the login page when the app has one', function () {
    Route::get('login', fn (): string => 'login')->name('login');
    app('router')->getRoutes()->refreshNameLookups();

    expect(authorizeBuilder()->isRedirect(url('login')))->toBeTrue()
        ->and(fn () => authorizeBuilder(json: true))->toThrow(HttpException::class, 'Log in');
});
