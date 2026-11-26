<?php

use App\Services\Faturamento\EtapasPedidoVenda;
use App\Services\ImportadorCentrosCusto;
use App\Services\Protheus\ImportadorEmpenhos;
use App\Services\Protheus\ImportadorEstruturas;
use App\Services\Protheus\ImportadorFaltasProduto;
use App\Services\Protheus\ImportadorFerramentas;
use App\Services\Protheus\ImportadorMaquinas;
use App\Services\Protheus\ImportadorMovimentosProducao;
use App\Services\Protheus\ImportadorNotasFiscaisOp;
use App\Services\Protheus\ImportadorOrdensProducao;
use App\Services\Protheus\ImportadorProdutos;
use App\Services\Protheus\ImportadorRoteiros;

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
            'via' => 'php',
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
            'via' => 'php',
        ],
        'produtos' => [
            'descricao' => 'Produtos',
            'tabela_origem' => env('PROTHEUS_TABELA_SB1') ?: 'SB1',
            'tabela_destino' => 'produtos_protheus',
            'importador' => ImportadorProdutos::class,
            'modo' => 'espelho',
            'via' => 'php',
        ],
        // Estrutura de produto (lista de materiais).
        'estruturas' => [
            'descricao' => 'Estruturas de produto',
            'tabela_origem' => env('PROTHEUS_TABELA_SG1') ?: 'SG1',
            'tabela_destino' => 'estruturas_produto',
            'importador' => ImportadorEstruturas::class,
            'modo' => 'espelho',
            'via' => 'php',
        ],
        // Ferramentas: mista. Os campos do Protheus são atualizados; as cavidades ativas e a unidade são do portal.
        'ferramentas' => [
            'descricao' => 'Ferramentas',
            'tabela_origem' => env('PROTHEUS_TABELA_SH4') ?: 'SH4',
            'tabela_destino' => 'ferramentas',
            'importador' => ImportadorFerramentas::class,
            'modo' => 'mista',
            'via' => 'php',
        ],
        // Roteiro de operações (depois das ferramentas, que ele cita).
        'roteiros' => [
            'descricao' => 'Roteiros de operação',
            'tabela_origem' => env('PROTHEUS_TABELA_SG2') ?: 'SG2',
            'tabela_destino' => 'roteiros_operacao',
            'importador' => ImportadorRoteiros::class,
            'modo' => 'espelho',
            'via' => 'php',
        ],
        // Depois dos produtos: a OP mostra o produto (SB1) pelo código.
        'ordens_producao' => [
            'descricao' => 'Ordens de produção',
            'tabela_origem' => env('PROTHEUS_TABELA_SC2') ?: 'SC2',
            'tabela_destino' => 'ordens_producao',
            'importador' => ImportadorOrdensProducao::class,
            'modo' => 'espelho', // cópia da SC2 + "status" e "custo_meta" (SB9) calculados pelo portal
            // Sincronização pela procedure do banco (MERGE da tabela inteira Compras.dbo.SC2, pelo R_E_C_N_O_), que o PHP
            // só chama, sem parâmetros. "php" = pelo importador (ImportadorOrdensProducao). Sem SQL Server (ex.: testes
            // em SQLite), sempre pelo PHP. A procedure lê SEMPRE Compras.dbo.SC2 e Compras.dbo.SB9 e a tabela inteira
            // (PROTHEUS_TABELA_SC2, PROTHEUS_TABELA_SB9 e PROTHEUS_SC2_DESDE valem só para o PHP).
            'via' => env('PROTHEUS_VIA_SC2') ?: 'procedure',
            'procedure' => 'dbo.sp_sincronizar_ordens_producao',
        ],
        // Empenhos (SD4): materiais reservados para cada OP; o saldo (D4_QUANT) cai com as baixas.
        'empenhos_op' => [
            'descricao' => 'Empenhos OP',
            'tabela_origem' => env('PROTHEUS_TABELA_SD4') ?: 'SD4',
            'tabela_destino' => 'empenhos_op',
            'importador' => ImportadorEmpenhos::class,
            'modo' => 'espelho',
            // Pela procedure: lê a SD4010 INTEIRA do servidor vinculado ALUMBRAPRD (banco CMT8F4_145045_PR_PD), só com OP,
            // com MERGE, e APAGA os excluídos no Protheus. "php" = pelo importador (testes em SQLite).
            'via' => env('PROTHEUS_VIA_SD4') ?: 'procedure',
            'procedure' => 'dbo.sp_sincronizar_empenhos_op',
            'origem_procedure' => '[ALUMBRAPRD].[CMT8F4_145045_PR_PD].[dbo].[SD4010]',
        ],
        // Falta de produtos (ZB5, tabela customizada): produtos que estão faltando ser produzidos (Produção › Falta de
        // produtos). Pela procedure (MERGE da ZB5 inteira, pelo R_E_C_N_O_); "php" = pelo importador.
        'faltas_produto' => [
            'descricao' => 'Falta de produtos',
            'tabela_origem' => 'ZB5', // a procedure lê sempre Compras.dbo.ZB5
            'tabela_destino' => 'faltas_produto',
            'importador' => ImportadorFaltasProduto::class,
            'modo' => 'espelho',
            'via' => env('PROTHEUS_VIA_ZB5') ?: 'procedure',
            'procedure' => 'dbo.sp_sincronizar_faltas_produto',
        ],
        // Movimentos internos (SD3) que têm OP: produções e consumos (baixas) das ordens de produção.
        'movimentos_producao' => [
            'descricao' => 'Apontamentos OP',
            'tabela_origem' => env('PROTHEUS_TABELA_SD3') ?: 'SD3',
            'tabela_destino' => 'movimentos_producao',
            'importador' => ImportadorMovimentosProducao::class,
            'modo' => 'espelho',
            // Pela procedure: lê a SD3010 do servidor vinculado ALUMBRAPRD (banco CMT8F4_145045_PR_PD) só no período
            // aberto do estoque (D3_EMISSAO > último B9_DATA da SB9010), com MERGE, e APAGA os excluídos no Protheus.
            // "php" = pelo importador (testes em SQLite), lendo tabela_origem na conexão protheus.
            'via' => env('PROTHEUS_VIA_SD3') ?: 'procedure',
            'procedure' => 'dbo.sp_sincronizar_movimentos_producao',
            'origem_procedure' => '[ALUMBRAPRD].[CMT8F4_145045_PR_PD].[dbo].[SD3010]',
        ],
        // Pedidos de venda: SC5 (cabeçalho) + SC6 (itens) + nome do cliente (SA1) numa tabela só, uma linha por item,
        // e as liberações (SC9) em pedidos_venda_liberacoes, no MESMO sincronismo (Faturamento, 26/11/2026).
        // SÓ pela procedure (MERGE pelo R_E_C_N_O_, lendo o servidor vinculado ALUMBRAPRD): o portal NUNCA acessa o
        // Protheus direto; não há importador em PHP; fora do SQL Server (testes) a sincronização é pulada. Depois da
        // procedure, o PHP recalcula as etapas dos pedidos só com as tabelas do portal ("depois").
        'pedidos_venda' => [
            'descricao' => 'Pedidos de venda',
            'tabela_origem' => 'SC6', // a procedure lê SC6010 + SC5010 + SA1010 do ALUMBRAPRD
            'tabela_destino' => 'pedidos_venda',
            'importador' => null,
            'modo' => 'espelho',
            'via' => 'procedure',
            'procedure' => 'dbo.sp_sincronizar_pedidos_venda',
            'origem_procedure' => '[ALUMBRAPRD].[CMT8F4_145045_PR_PD].[dbo].[SC5010] + [SC6010] + [SC9010]',
            'depois' => EtapasPedidoVenda::class,
            // Outras tabelas gravadas pela mesma procedure (documentação do banco).
            'outras_tabelas' => ['pedidos_venda_liberacoes' => '[ALUMBRAPRD].[CMT8F4_145045_PR_PD].[dbo].[SC9010]'],
        ],
        // Itens de nota fiscal de saída (SD2) que citam uma OP.
        'notas_fiscais_op' => [
            'descricao' => 'Notas fiscais com OP',
            'tabela_origem' => env('PROTHEUS_TABELA_SD2') ?: 'SD2',
            'tabela_destino' => 'notas_fiscais_op',
            'importador' => ImportadorNotasFiscaisOp::class,
            'modo' => 'espelho',
            'via' => 'php',
        ],
    ],

    // Campo da OP nos movimentos (SD3) e nas notas (SD2). Só entram as linhas com esse campo preenchido.
    'sd3_campo_op' => env('PROTHEUS_SD3_CAMPO_OP', 'D3_ORDEM'),
    'sd2_campo_op' => env('PROTHEUS_SD2_CAMPO_OP', 'D2_OP'),

    // Campo de quantidade de cavidades da ferramenta na SH4.
    'sh4_campo_cavidades' => env('PROTHEUS_SH4_CAMPO_CAVIDADES', 'H4_QTDCAV'),

    // Saldos de fechamento (SB9): só lidos para preencher ordens_producao.custo_meta. A SB9 não é copiada.
    'tabela_sb9' => env('PROTHEUS_TABELA_SB9') ?: 'SB9',

    // Saldos atuais em estoque (SB2): só lidos para o custo médio do item (B2_CM1), que valoriza o movimento da OP
    // quando o Protheus ainda não gravou o custo (D3_CUSTO1 / D2_CUSTO1 zerado). A SB2 não é copiada.
    'tabela_sb2' => env('PROTHEUS_TABELA_SB2') ?: 'SB2',

    // Legendas do D3_CF (tipo de movimento da SD3). O tipo decide o cálculo do custo da OP:
    // producao (PR) = quantidade produzida; consumo (RE) = soma no custo; devolucao (DE) = abate do custo.
    // Códigos fora da lista usam a regra pelo prefixo (PR, RE, DE). "NF" é o item de nota fiscal (SD2).
    'tipos_movimento' => [
        'producao' => 'Produção',
        'consumo' => 'Baixa / consumo',
        'devolucao' => 'Devolução de consumo',
        'nota_fiscal' => 'Nota fiscal',
        'outro' => 'Outro movimento',
    ],
    'prefixos_d3_cf' => ['PR' => 'producao', 'RE' => 'consumo', 'DE' => 'devolucao'],
    'legendas_d3_cf' => [
        'PR0' => 'Produção (apontamento)',
        'PR1' => 'Produção automática',
        'RE0' => 'Baixa manual',
        'RE1' => 'Baixa automática (empenho)',
        'RE2' => 'Baixa de produto intermediário',
        'RE3' => 'Baixa por apropriação indireta',
        'RE4' => 'Transferência (saída)',
        'RE5' => 'Baixa para a OP',
        'RE6' => 'Baixa valorizada',
        'RE7' => 'Transferência múltipla (saída)',
        'RE9' => 'Baixa de mão de obra',
        'DE0' => 'Devolução manual',
        'DE1' => 'Devolução automática (empenho)',
        'DE2' => 'Devolução de produto intermediário',
        'DE3' => 'Devolução por apropriação indireta',
        'DE4' => 'Transferência (entrada)',
        'DE5' => 'Devolução da OP',
        'DE6' => 'Devolução valorizada',
        'DE7' => 'Transferência múltipla (entrada)',
        'DE9' => 'Devolução de mão de obra',
        'NF' => 'Nota fiscal',
    ],

    // Sincronização automática (expressão cron). Vazio = desligada. Exemplos: "0 * * * *" (de hora em hora),
    // "*/30 * * * *" (a cada 30 minutos), "0 6 * * *" (todo dia às 6h). Precisa do agendador do Laravel rodando
    // como serviço (veja o README, "Sincronização automática com o Protheus").
    'agenda' => env('PROTHEUS_SINCRONIZAR_CRON', ''),

    // Leitura completa das tabelas incrementais (SD3, SD2), para pegar mudanças antigas fora da janela de releitura.
    // Padrão: domingo às 03:17 (só se a sincronização automática estiver ligada). Vazio = nunca (use o comando
    // "php artisan protheus:sincronizar --completa").
    'agenda_completa' => env('PROTHEUS_SINCRONIZAR_CRON_COMPLETA', '17 3 * * 0'),

    // Movimentos (SD3, SD2): a cada sincronização são lidos os registros novos e os dos últimos N dias (estornos e
    // exclusões costumam ser recentes). A primeira sincronização e a completa leem tudo.
    'reler_dias' => (int) env('PROTHEUS_RELER_DIAS', 45),

    // Botão da tela Banco de dados: cada etapa (requisição) trabalha no máximo este tempo (segundos) e a tela chama a
    // próxima. Deixe bem abaixo do "activityTimeout" do FastCGI no IIS (padrão 70 s).
    'segundos_por_etapa' => (int) env('PROTHEUS_SEGUNDOS_POR_ETAPA', 25),

    // Ordens de produção, movimentos (SD3) e notas (SD2): só os emitidos a partir desta data (AAAAMMDD). Vazio = todos.
    'sc2_desde' => env('PROTHEUS_SC2_DESDE', ''),

    // Linhas por lote: o Protheus é lido e gravado de tantas em tantas linhas, para a memória não crescer com o
    // tamanho da tabela (ex.: SC2 com anos de OPs).
    'lote' => (int) env('PROTHEUS_SINCRONIZAR_LOTE', 1000),

    // Uma sincronização em andamento dá sinal de vida a cada lote. Sem sinal por este tempo (segundos), ela é dada
    // como interrompida (o processo morreu) e libera a próxima.
    'sem_sinal' => (int) env('PROTHEUS_SINCRONIZAR_SEM_SINAL', 180),

];
