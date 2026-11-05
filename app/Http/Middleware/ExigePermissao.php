<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Uso: ->middleware('permissao:calculadora'). Admin pode tudo; os demais dependem dos grupos.
 * Usuário ainda sem acesso vai para a página "aguardando liberação".
 */
class ExigePermissao
{
    public function handle(Request $request, Closure $next, string $permissao): Response
    {
        $usuario = $request->user();

        if (! $usuario->temAcesso()) {
            return redirect()->route('aguardando');
        }
        abort_unless($usuario->pode($permissao), 403, 'Seus grupos não dão acesso a esta página. Fale com um administrador.');

        return $next($request);
    }
}
