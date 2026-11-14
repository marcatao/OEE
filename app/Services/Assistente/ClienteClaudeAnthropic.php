<?php

namespace App\Services\Assistente;

use Anthropic\Client;
use RuntimeException;

/** Cliente real: SDK oficial da Anthropic para PHP (anthropic-ai/sdk). */
class ClienteClaudeAnthropic implements ClienteClaude
{
    private ?Client $cliente = null;

    public function enviar(array $requisicao): array
    {
        $fallback = (bool) config('assistente.fallback');
        $mensagem = $this->cliente()->beta->messages->create(
            maxTokens: (int) config('assistente.max_tokens', 8000),
            messages: $requisicao['messages'],
            model: (string) config('assistente.modelo'),
            system: $requisicao['system'],
            thinking: ['type' => 'adaptive'],
            outputConfig: ['effort' => (string) config('assistente.esforco', 'medium')],
            tools: $requisicao['tools'] ?: null,
            toolChoice: $requisicao['tool_choice'] ?? null,
            fallbacks: $fallback ? 'default' : null,
            betas: $fallback ? ['server-side-fallback-2026-07-01'] : null,
        );

        $dados = json_decode(json_encode($mensagem), true);

        return [
            'content' => $dados['content'] ?? [],
            'stop_reason' => $dados['stop_reason'] ?? null,
            'model' => (string) ($dados['model'] ?? config('assistente.modelo')),
            'usage' => $dados['usage'] ?? [],
        ];
    }

    private function cliente(): Client
    {
        $chave = (string) config('assistente.chave');
        if ($chave === '') {
            throw new RuntimeException('Assistente sem chave da API (ANTHROPIC_API_KEY no .env).');
        }

        return $this->cliente ??= new Client(apiKey: $chave, requestOptions: [
            'timeout' => (float) config('assistente.timeout', 60),
            'maxRetries' => 0, // cada etapa precisa caber no tempo do IIS; a tela avisa e o usuário pergunta de novo
        ]);
    }
}
