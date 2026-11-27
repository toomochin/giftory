<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        if (getenv('GIFTORY_TRUST_PROXY') === 'true') {
            $middleware->trustProxies(at: '*');
        }
        // APIではログイン画面に転送せず、直接URLを開いた場合もJSONの認証エラーを返す。
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : url('/'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
