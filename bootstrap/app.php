<?php

use App\Http\Middleware\ExigePerfil;
use App\Http\Middleware\ExigePermissao;
use App\Http\Middleware\UsuarioAtivo;
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
        $middleware->web(append: [UsuarioAtivo::class]);
        $middleware->alias(['perfil' => ExigePerfil::class, 'permissao' => ExigePermissao::class]);
        $middleware->redirectGuestsTo(fn () => route('login'));
        // Proxies confiáveis (TRUSTED_PROXIES no .env) ficam em config/trustedproxy.php: aqui o .env ainda não foi lido.
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
