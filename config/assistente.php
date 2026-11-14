<?php

/*
 * "Pergunte para Alumbra": assistente de IA da página inicial, com a Claude (Anthropic).
 * Só responde sobre o portal e só com os dados que o usuário logado pode ver (as consultas seguem as permissões e as
 * unidades dele). A chave da API fica só no .env (ANTHROPIC_API_KEY), nunca no código.
 */
return [
    // Chave da API da Anthropic (console.anthropic.com). Sem chave, o box avisa que o assistente não está configurado.
    'chave' => env('ANTHROPIC_API_KEY'),

    // Modelo da Claude e esforço de raciocínio (low | medium | high | xhigh | max).
    'modelo' => env('ASSISTENTE_MODELO', 'claude-opus-5-5'),
    'esforco' => env('ASSISTENTE_ESFORCO', 'medium'),

    // Se a Claude recusar uma pergunta por segurança, tenta de novo num modelo alternativo (fallback do servidor).
    'fallback' => (bool) env('ASSISTENTE_FALLBACK', true),

    // Tamanho máximo de cada resposta (tokens). Mantém cada etapa curta (ver "timeout").
    'max_tokens' => (int) env('ASSISTENTE_MAX_TOKENS', 8000),

    // Etapas (chamadas à Claude) por pergunta. Cada etapa é uma requisição curta (não estoura o tempo do IIS); na
    // última a Claude responde com o que já consultou.
    'max_etapas' => (int) env('ASSISTENTE_MAX_ETAPAS', 8),

    // Perguntas por usuário por dia (0 = sem limite). Controla o gasto de tokens.
    'limite_diario' => (int) env('ASSISTENTE_LIMITE_DIARIO', 60),

    // Linhas no máximo devolvidas por consulta (a Claude pode pedir resumos agrupados em vez de listas longas).
    'max_linhas' => (int) env('ASSISTENTE_MAX_LINHAS', 300),

    // Mensagens antigas da conversa que continuam sendo enviadas (as mais antigas saem). Uma "Nova conversa" zera.
    'max_mensagens' => (int) env('ASSISTENTE_MAX_MENSAGENS', 40),

    // Tempo máximo de cada chamada à Claude (segundos), sem nova tentativa automática. Deixe abaixo do "activityTimeout"
    // do FastCGI no IIS (padrão 70 s); se respostas longas forem cortadas, aumente os dois (ex.: IIS 180 s e aqui 150).
    'timeout' => (float) env('ASSISTENTE_TIMEOUT', 60),
];
