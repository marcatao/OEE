<?php

namespace App\Services\Assistente;

/**
 * Envia uma requisição à API de mensagens da Claude e devolve a resposta no formato da API (arrays), para gravar no
 * histórico e reenviar. Os testes trocam a implementação por uma falsa (sem rede e sem gastar tokens).
 */
interface ClienteClaude
{
    /**
     * @param  array{system: list<array>, messages: list<array>, tools: list<array>, tool_choice?: array}  $requisicao
     * @return array{content: list<array<string, mixed>>, stop_reason: ?string, model: string, usage: array<string, int>}
     */
    public function enviar(array $requisicao): array;
}
