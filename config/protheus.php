<?php

use App\Services\ImportadorCentrosCusto;

/*
 * Integração com o Protheus (só leitura). A conexão fica em config/database.php ("protheus").
 * Cada item de "sincronizacoes" é uma tabela do portal alimentada por uma tabela do Protheus.
 * Para sincronizar outra tabela no futuro: crie o importador (como ImportadorCentrosCusto) e acrescente aqui.
 */
return [
    'sincronizacoes' => [
        'centros_custo' => [
            'descricao' => 'Centros de custo',
            'tabela_origem' => env('PROTHEUS_TABELA_CTT') ?: 'CTT', // tabela no banco do Protheus (ex.: CTT ou dbo.CTT)
            'tabela_destino' => 'centros_custo',                   // tabela do portal
            'importador' => ImportadorCentrosCusto::class,
        ],
    ],

    // Sincronização automática (expressão cron). Vazio = desligada. Exemplos: "0 * * * *" (de hora em hora),
    // "*/30 * * * *" (a cada 30 minutos), "0 6 * * *" (todo dia às 6h). Precisa do agendador do Laravel rodando
    // como serviço (veja o README, "Sincronização automática com o Protheus").
    'agenda' => env('PROTHEUS_SINCRONIZAR_CRON', ''),

    // Tempo máximo (segundos) de uma sincronização; evita duas rodando ao mesmo tempo.
    'tempo_maximo' => (int) env('PROTHEUS_SINCRONIZAR_TEMPO_MAXIMO', 600),
];
