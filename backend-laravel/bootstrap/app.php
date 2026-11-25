<?php

use App\Http\Controllers\EventSubController;
use App\Http\Middleware\OnlyWebhookThroughTunnel;
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
        then: function () {
            // Webhook do EventSub: fora dos grupos web/api (sem sessão, sem CSRF). A segurança é a assinatura HMAC.
            Route::post('/twitch/eventsub', EventSubController::class)
                ->middleware('throttle:600,1')
                ->name('twitch.eventsub');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sessão + CSRF do Sanctum para o SPA (o React chama /api pelo mesmo endereço via proxy do Vite).
        $middleware->statefulApi();

        // Pelo túnel da Cloudflare só o webhook passa.
        $middleware->prepend(OnlyWebhookThroughTunnel::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
