<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Uso: ->middleware('perfil:supervisor'). Hierarquia: operador < supervisor < admin. */
class ExigePerfil
{
    public function handle(Request $request, Closure $next, string $minimo): Response
    {
        abort_unless($request->user()?->temPerfil($minimo), 403, 'Seu perfil não tem acesso a esta página.');

        return $next($request);
    }
}
