<?php

namespace App\Http\Middleware;

use App\Models\AcessoPagina;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Controle de acessos: grava em `acessos_paginas` cada PÁGINA (GET que devolve HTML com sucesso) aberta por um usuário
 * logado no portal. Não grava chamadas JSON/AJAX, trechos recarregados (`?parcial=1`), arquivos nem a API do app.
 * Também mantém `usuarios.ultimo_acesso_em` (no máximo a cada `oee.acessos_paginas.minutos_ultimo_acesso`).
 * Uma falha aqui nunca derruba a página (só vai para o log).
 */
class RegistrarAcessoPagina
{
    public function handle(Request $request, Closure $next): Response
    {
        $resposta = $next($request);

        if ($this->deveRegistrar($request, $resposta)) {
            try {
                $this->registrar($request);
            } catch (Throwable $e) {
                Log::warning('Não foi possível registrar o acesso à página', ['erro' => $e->getMessage()]);
            }
        }

        return $resposta;
    }

    private function deveRegistrar(Request $request, Response $resposta): bool
    {
        return config('oee.acessos_paginas.registrar', true)
            && $request->isMethod('GET')
            && $request->user() !== null
            && ! $request->is('api/*')
            && ! $request->ajax()
            && ! $request->expectsJson()
            && ! $request->boolean('parcial')
            && $resposta->getStatusCode() === 200
            && str_starts_with((string) $resposta->headers->get('Content-Type'), 'text/html');
    }

    private function registrar(Request $request): void
    {
        $usuario = $request->user();
        $agora = now();

        AcessoPagina::create([
            'usuario_id' => $usuario->id,
            'data_hora' => $agora,
            'rota' => Str::limit((string) $request->route()?->getName(), 117) ?: null,
            'caminho' => Str::limit('/'.ltrim($request->path(), '/'), 297),
            'ip' => $request->ip(),
        ]);

        $intervalo = (int) config('oee.acessos_paginas.minutos_ultimo_acesso', 5);
        if (! $usuario->ultimo_acesso_em || $usuario->ultimo_acesso_em->lt($agora->copy()->subMinutes($intervalo))) {
            // Direto na tabela: não mexe em "atualizado_em" do cadastro (não é uma alteração do usuário).
            DB::table('usuarios')->where('id', $usuario->id)->update(['ultimo_acesso_em' => $agora]);
            $usuario->ultimo_acesso_em = $agora;
        }
    }
}
