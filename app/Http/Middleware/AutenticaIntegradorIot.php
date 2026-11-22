<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rotas do Integrador MQTT (api/iot/*): só com o token de config('oee.iot.token') no cabeçalho Authorization: Bearer.
 * Sem token configurado no portal, a rota fica desligada (503).
 */
class AutenticaIntegradorIot
{
    public function handle(Request $request, Closure $next): Response
    {
        $esperado = (string) config('oee.iot.token');
        if ($esperado === '') {
            return response()->json(['erro' => 'desligado', 'mensagem' => 'IOT_TOKEN não configurado no portal.'], 503);
        }
        if (! hash_equals($esperado, (string) $request->bearerToken())) {
            return response()->json(['erro' => 'token_invalido', 'mensagem' => 'Token do integrador inválido.'], 401);
        }

        return $next($request);
    }
}
