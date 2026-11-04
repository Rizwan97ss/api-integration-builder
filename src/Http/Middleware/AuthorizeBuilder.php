<?php

namespace DevForge\ApiDev\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Decides who may open the builder, including in apps without a login page.
 *
 * Logged-in users get in. Guests go to the login page when the app has one.
 * Without one, the builder is open only on a local machine, because Laravel's
 * own `auth` middleware would fail with "Route [login] not defined".
 */
class AuthorizeBuilder
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() !== null) {
            return $next($request);
        }

        if (Route::has('login')) {
            abort_if($request->expectsJson(), 401, 'Log in to use the API Integration Builder.');

            return redirect()->guest(route('login'));
        }

        abort_unless(app()->environment('local'), 403, 'The API Integration Builder is only open on APP_ENV=local in apps without a login page. Set integration-builder.routes.middleware to choose who can use it.');

        return $next($request);
    }
}
