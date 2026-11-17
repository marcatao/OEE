<?php

/*
 * Pedidos de compra pelo Protheus, por TABELAS MURO nativas do Protheus (ZP1/ZP2, integracao-protheus/pedido-compra/):
 * o portal grava o pedido (cabeçalho + itens) com STATUS = AGUARDANDO_PROTHEUS; o fonte OEEPCJOB (schedule do Protheus)
 * gera o pedido (MATA120), devolve o número e, depois, a aprovação, a conclusão e os valores. O portal lê de volta
 * (comando compras:atualizar-pedidos no agendador e ao abrir a solicitação de material ou a OS).
 */
return [
    // Conexão onde estão as tabelas muro (config/database.php "muro": por padrão o banco do Protheus).
    'conexao' => env('COMPRAS_MURO_CONEXAO', 'muro'),

    // Tabelas NATIVAS do Protheus, criadas pelo compatibilizador U_OEEUPDPC (dicionário SX2/SX3/SIX):
    // alias (prefixo dos campos, ex.: ZP1_STATUS) e nome físico (alias + empresa, ex.: ZP1010).
    'alias_pedidos' => env('COMPRAS_MURO_ALIAS', 'ZP1'),
    'alias_itens' => env('COMPRAS_MURO_ALIAS_ITENS', 'ZP2'),
    'tabela_pedidos' => env('COMPRAS_MURO_TABELA', env('COMPRAS_MURO_ALIAS', 'ZP1').'010'),
    'tabela_itens' => env('COMPRAS_MURO_TABELA_ITENS', env('COMPRAS_MURO_ALIAS_ITENS', 'ZP2').'010'),

    // Filial do Protheus em que o pedido é gerado (vazio = a filial em que o job do Protheus roda).
    'filial' => env('COMPRAS_FILIAL', ''),

    // As datas da muro (campos D = AAAAMMDD + hora HH:MM:SS) ficam no horário local.
    'fuso_muro' => env('COMPRAS_MURO_FUSO', 'America/Sao_Paulo'),

    // Leitura dos status no agendador do Laravel (vazio = desligado).
    'agenda' => env('COMPRAS_ATUALIZAR_CRON', '*/10 * * * *'),
];
