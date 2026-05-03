<?php

use App\Http\Middleware\EnsureTecnico;
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
        $middleware->alias([
            'tecnico' => EnsureTecnico::class,
        ]);

        // Confiar en proxies (reverse proxy con TLS termination) para que
        // Laravel respete X-Forwarded-Proto/Host y genere URLs https correctas.
        // Necesario en SiteGround prod (Bloque 15) y en cualquier setup HTTPS
        // detrás de proxy (descubierto durante smoke 11c con ngrok).
        $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO
            | Request::HEADER_X_FORWARDED_AWS_ELB);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
