<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cria no banco dos testes (SQLite, conexão compras.conexao) as tabelas muro dos pedidos de compra, iguais às do
 * integracao-protheus/pedido-compra/01-tabelas-muro.sql (que roda no banco do Protheus).
 */
trait TabelaMuroDeCompras
{
    protected function criarTabelasMuro(): void
    {
        $esquema = Schema::connection(config('compras.conexao'));
        $esquema->create(config('compras.tabela_pedidos'), function (Blueprint $t) {
            $t->increments('ID');
            $t->integer('ID_PORTAL')->unique();
            $t->string('ORIGEM', 30)->default('');
            $t->string('FILIAL', 8)->default('');
            $t->string('CC_ORIGEM', 20)->default('');
            $t->string('CC_DESTINO', 20)->default('');
            $t->string('FORNECEDOR', 10)->default('');
            $t->string('LOJA', 4)->default('');
            $t->string('COND_PAGTO', 3)->default('');
            $t->string('SOLICITANTE', 120)->default('');
            $t->string('OBSERVACAO', 500)->default('');
            $t->string('STATUS', 25)->default('AGUARDANDO_PROTHEUS');
            $t->string('MENSAGEM', 1000)->nullable();
            $t->string('NUM_PEDIDO', 10)->default('');
            $t->dateTime('INTEGRADO_EM')->nullable();
            $t->string('APROVADOR', 120)->nullable();
            $t->dateTime('APROVADO_EM')->nullable();
            $t->dateTime('CONCLUIDO_EM')->nullable();
            $t->decimal('VALOR_TOTAL', 18, 2)->nullable();
            $t->integer('TENTATIVAS')->default(0);
            $t->dateTime('CRIADO_EM')->nullable();
            $t->dateTime('ATUALIZADO_EM')->nullable();
        });
        $esquema->create(config('compras.tabela_itens'), function (Blueprint $t) {
            $t->increments('ID');
            $t->integer('ID_PEDIDO');
            $t->string('ITEM', 4);
            $t->integer('ID_ITEM_PORTAL');
            $t->string('PRODUTO', 30);
            $t->string('DESCRICAO', 120)->default('');
            $t->string('UM', 2)->default('');
            $t->decimal('QUANTIDADE', 14, 4);
            $t->decimal('PRECO', 18, 4)->default(0);
            $t->string('OBSERVACAO', 250)->default('');
            $t->string('C7_ITEM', 4)->default('');
            $t->decimal('VALOR_UNITARIO', 18, 4)->nullable();
            $t->decimal('VALOR_TOTAL', 18, 2)->nullable();
            $t->decimal('QTD_ENTREGUE', 14, 4)->nullable();
            $t->unique(['ID_PEDIDO', 'ITEM']);
        });
    }
}
