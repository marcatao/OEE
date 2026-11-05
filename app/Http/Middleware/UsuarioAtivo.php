<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * O usuário é relido do banco a cada requisição: desativá-lo no portal derruba a sessão na hora.
 */
class UsuarioAtivo
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && ! $request->user()->ativo) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect('/')->with('erro', 'Seu acesso ao portal está desativado.');
        }

        return $next($request);
    }
}
