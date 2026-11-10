<?php

use App\Services\ImportadorCentrosCusto;
use App\Services\Protheus\ImportadorMaquinas;
use App\Services\Protheus\ImportadorOrdensProducao;
use App\Services\Protheus\ImportadorProdutos;

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
            'modo' => 'espelho', // o portal é cópia da tabela do Protheus
        ],
        // Depois dos centros de custo, porque a máquina usa o código do centro de custo.
        'maquinas' => [
            'descricao' => 'Máquinas',
            'tabela_origem' => env('PROTHEUS_TABELA_SHB') ?: 'SHB',  // centros de trabalho
            'tabela_destino' => 'maquinas',
            'importador' => ImportadorMaquinas::class,
            // Mista: código, nome e centro de custo vêm da SHB só na primeira vez; o resto é do sistema OEE.
            // Máquina nova chega inativa; depois só a exclusão no Protheus altera o registro.
            'modo' => 'mista',
        ],
        'produtos' => [
            'descricao' => 'Produtos',
            'tabela_origem' => env('PROTHEUS_TABELA_SB1') ?: 'SB1',
            'tabela_destino' => 'produtos_protheus',
            'importador' => ImportadorProdutos::class,
            'modo' => 'espelho',
        ],
        // Depois dos produtos: a OP mostra o produto (SB1) pelo código.
        'ordens_producao' => [
            'descricao' => 'Ordens de produção',
            'tabela_origem' => env('PROTHEUS_TABELA_SC2') ?: 'SC2',
            'tabela_destino' => 'ordens_producao',
            'importador' => ImportadorOrdensProducao::class,
            'modo' => 'espelho', // cópia da SC2 + o campo "status" calculado pelo portal
        ],
    ],

    // Sincronização automática (expressão cron). Vazio = desligada. Exemplos: "0 * * * *" (de hora em hora),
    // "*/30 * * * *" (a cada 30 minutos), "0 6 * * *" (todo dia às 6h). Precisa do agendador do Laravel rodando
    // como serviço (veja o README, "Sincronização automática com o Protheus").
    'agenda' => env('PROTHEUS_SINCRONIZAR_CRON', ''),

    // Ordens de produção: só as emitidas a partir desta data (AAAAMMDD). Vazio = todas.
    'sc2_desde' => env('PROTHEUS_SC2_DESDE', ''),

    // Linhas por lote: o Protheus é lido e gravado de tantas em tantas linhas, para a memória não crescer com o
    // tamanho da tabela (ex.: SC2 com anos de OPs).
    'lote' => (int) env('PROTHEUS_SINCRONIZAR_LOTE', 1000),

    // Tempo máximo (segundos) de uma sincronização; evita duas rodando ao mesmo tempo.
    'tempo_maximo' => (int) env('PROTHEUS_SINCRONIZAR_TEMPO_MAXIMO', 600),
];
