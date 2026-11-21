<?php

use App\Http\Controllers\InstallController;
use App\Http\Middleware\ForumContext;
use App\Http\Middleware\RedirectToInstaller;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // The installer has to work before there is a database, so it runs without the web middleware.
        then: function () {
            Route::get('install', [InstallController::class, 'show'])->name('install');
            Route::post('install', [InstallController::class, 'store']);
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(RedirectToInstaller::class);
        $middleware->append(SecurityHeaders::class);
        $middleware->encryptCookies(except: ['theme']);
        $middleware->validateCsrfTokens(except: ['ads/payment/paytr']);
        $middleware->web(append: [ForumContext::class]);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('forum.index'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
