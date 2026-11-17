<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Pedidos de compra gerados no portal pelo administrador da unidade, a partir dos itens APROVADOS de uma solicitação de
 * material ou direto numa ordem de serviço. O pedido vai para o Protheus pela tabela muro (config/compras.php,
 * integracao-protheus/pedido-compra/); estas tabelas guardam a cópia do portal e o que o Protheus devolveu (número,
 * aprovação, conclusão e valores), lida pelo App\Services\Compras\PedidoCompraMuro.
 * Só CRIA tabelas: nenhum registro existente é alterado. Sem cascata.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedidos_compra', function (Blueprint $table) {
            $table->id();                                            // = ID_PORTAL na tabela muro
            $table->string('unidade', 20);                           // manutencao | tecnologia | ferramentaria (quem administra)
            $table->foreignId('solicitacao_material_id')->nullable()->constrained('solicitacoes_material');
            $table->foreignId('ordem_servico_id')->nullable()->constrained('ordens_servico');
            $table->string('filial', 8)->default('');                // filial do Protheus (vazio = a do job)
            $table->string('centro_custo_origem', 20);               // CTT_CUSTO de quem pede
            $table->string('centro_custo_destino', 20);              // CTT_CUSTO que recebe o custo (C7_CC)
            $table->string('observacao', 500)->nullable();
            $table->string('status', 25)->default('aguardando_protheus');
            $table->string('numero_pedido', 10)->nullable();         // C7_NUM devolvido pelo Protheus
            $table->string('mensagem', 1000)->nullable();            // erro do ExecAuto ou aviso do Protheus
            $table->dateTime('integrado_em')->nullable();            // UTC
            $table->string('aprovador', 120)->nullable();            // nome de quem aprovou no Protheus
            $table->dateTime('aprovado_em')->nullable();
            $table->dateTime('concluido_em')->nullable();
            $table->decimal('valor_total', 18, 2)->nullable();       // valor do pedido (concluído: o entregue)
            $table->dateTime('consultado_em')->nullable();           // última leitura da tabela muro
            $table->dateTime('criado_em')->nullable();
            $table->foreignId('criado_por_id')->nullable()->constrained('usuarios');
            $table->dateTime('atualizado_em')->nullable();
            $table->foreignId('atualizado_por_id')->nullable()->constrained('usuarios');
            $table->index(['solicitacao_material_id', 'id']);
            $table->index(['ordem_servico_id', 'id']);
            $table->index(['status', 'id']);                         // pendentes de leitura no Protheus
        });

        Schema::create('pedidos_compra_itens', function (Blueprint $table) {
            $table->id();                                            // = ID_ITEM_PORTAL na tabela muro
            $table->foreignId('pedido_compra_id')->constrained('pedidos_compra');
            $table->foreignId('solicitacao_material_item_id')->nullable()->constrained('solicitacoes_material_itens');
            $table->string('item', 4);                               // 0001, 0002... (C7_ITEM)
            $table->string('produto_codigo', 30);                    // B1_COD
            $table->string('produto_descricao', 120)->default('');
            $table->string('um', 4)->default('');
            $table->decimal('quantidade', 14, 4);
            $table->string('observacao', 250)->nullable();
            $table->decimal('valor_unitario', 18, 4)->nullable();    // C7_PRECO
            $table->decimal('valor_total', 18, 2)->nullable();       // C7_TOTAL
            $table->decimal('quantidade_entregue', 14, 4)->nullable(); // C7_QUJE
            $table->dateTime('criado_em')->nullable();
            $table->foreignId('criado_por_id')->nullable()->constrained('usuarios');
            $table->dateTime('atualizado_em')->nullable();
            $table->foreignId('atualizado_por_id')->nullable()->constrained('usuarios');
            $table->index(['pedido_compra_id', 'id']);
            $table->index('solicitacao_material_item_id');
        });

        if (DB::getDriverName() === 'sqlsrv') {
            foreach ([
                "ALTER TABLE pedidos_compra ADD CONSTRAINT ck_pedidos_compra_unidade CHECK (unidade IN ('manutencao', 'tecnologia', 'ferramentaria'))",
                "ALTER TABLE pedidos_compra ADD CONSTRAINT ck_pedidos_compra_status CHECK (status IN ('aguardando_protheus', 'aguardando_aprovacao', 'aprovado', 'concluido', 'reprovado', 'erro', 'cancelado'))",
                'ALTER TABLE pedidos_compra ADD CONSTRAINT ck_pedidos_compra_origem CHECK (solicitacao_material_id IS NOT NULL OR ordem_servico_id IS NOT NULL)',
                'ALTER TABLE pedidos_compra_itens ADD CONSTRAINT ck_pedidos_compra_itens_quantidade CHECK (quantidade > 0)',
            ] as $sql) {
                DB::statement($sql);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pedidos_compra_itens');
        Schema::dropIfExists('pedidos_compra');
    }
};
