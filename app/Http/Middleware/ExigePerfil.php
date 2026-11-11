<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Uso: ->middleware('perfil:admin_producao,admin_tecnologia'): só quem tem um desses perfis (config oee.perfis_acesso).
 * Usuário ainda sem acesso vai para a página "aguardando liberação".
 */
class ExigePerfil
{
    public function handle(Request $request, Closure $next, string ...$perfis): Response
    {
        $usuario = $request->user();

        if (! $usuario->temAcesso()) {
            return redirect()->route('aguardando');
        }
        abort_unless($usuario->temPerfil(...$perfis), 403, 'Seu perfil não dá acesso a esta página.');

        return $next($request);
    }
}
