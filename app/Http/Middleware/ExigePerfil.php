<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Uso: ->middleware('perfil:admin'). Só administradores. */
class ExigePerfil
{
    public function handle(Request $request, Closure $next, string $perfil): Response
    {
        $usuario = $request->user();

        if (! $usuario->temAcesso()) {
            return redirect()->route('aguardando');
        }
        abort_unless($usuario->perfil === $perfil, 403, 'Só administradores podem acessar esta página.');

        return $next($request);
    }
}
