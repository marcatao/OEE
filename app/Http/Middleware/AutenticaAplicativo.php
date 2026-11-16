<?php

namespace App\Http\Middleware;

use App\Services\Aplicativo\LoginAplicativo;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * API do aplicativo Android: o token vem no cabeçalho Authorization (Bearer) ou em X-Token-App (caso o IIS não
 * repasse o Authorization). O usuário do token vira o usuário da requisição, então os escopos de unidade e as
 * permissões são os mesmos das telas do portal.
 */
class AutenticaAplicativo
{
    public function __construct(private LoginAplicativo $login) {}

    public function handle(Request $request, Closure $next): Response
    {
        $registro = $this->login->validar($request->bearerToken() ?: $request->header('X-Token-App'));
        if (! $registro) {
            return response()->json(['erro' => 'sessao_expirada', 'mensagem' => 'Entre de novo no aplicativo.'], 401);
        }
        Auth::setUser($registro->usuario);
        $request->attributes->set('token_aplicativo', $registro);

        return $next($request);
    }
}
