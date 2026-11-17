<?php

/*
 * Pedidos de compra pelo Protheus, por uma TABELA MURO (integracao-protheus/pedido-compra/):
 * o portal grava o pedido (cabeçalho + itens) com STATUS = AGUARDANDO_PROTHEUS; o fonte OEEPCJOB (schedule do Protheus)
 * gera o pedido (MATA120), devolve o número e, depois, a aprovação, a conclusão e os valores. O portal lê de volta
 * (comando compras:atualizar-pedidos no agendador e ao abrir a solicitação de material ou a OS).
 */
return [
    // Conexão onde estão as tabelas muro (config/database.php "muro": por padrão o banco do Protheus).
    'conexao' => env('COMPRAS_MURO_CONEXAO', 'muro'),
    'tabela_pedidos' => env('COMPRAS_MURO_TABELA', 'OEE_PEDIDO_COMPRA'),
    'tabela_itens' => env('COMPRAS_MURO_TABELA_ITENS', 'OEE_PEDIDO_COMPRA_ITEM'),

    // Filial do Protheus em que o pedido é gerado (vazio = a filial em que o job do Protheus roda).
    'filial' => env('COMPRAS_FILIAL', ''),

    // As datas da tabela muro são gravadas no horário local (GETDATE() do servidor do banco).
    'fuso_muro' => env('COMPRAS_MURO_FUSO', 'America/Sao_Paulo'),

    // Leitura dos status no agendador do Laravel (vazio = desligado).
    'agenda' => env('COMPRAS_ATUALIZAR_CRON', '*/10 * * * *'),
];
