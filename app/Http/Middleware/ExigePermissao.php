<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Uso: ->middleware('permissao:calculadora') ou várias ('permissao:os_solicitar,os_aprovar' = basta uma).
 * Admin pode tudo; os demais dependem dos perfis e dos grupos.
 * Usuário ainda sem acesso vai para a página "aguardando liberação".
 */
class ExigePermissao
{
    public function handle(Request $request, Closure $next, string ...$permissoes): Response
    {
        $usuario = $request->user();

        if (! $usuario->temAcesso()) {
            return redirect()->route('aguardando');
        }
        abort_unless(collect($permissoes)->contains(fn ($p) => $usuario->pode($p)), 403, 'Seus grupos não dão acesso a esta página. Fale com um administrador.');

        return $next($request);
    }
}
