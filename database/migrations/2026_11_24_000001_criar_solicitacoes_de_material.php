<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Ordens de serviço › Solicitação de material: o mantenedor pede material à administração da área (Manutenção ou
 * Tecnologia); o administrador analisa ITEM a ITEM (aprova a quantidade, que pode ser menor, ou reprova com motivo).
 * Depois o item aprovado vira requisição ao almoxarifado ou solicitação de compra (encaminhamento: etapa futura).
 * - solicitacoes_material: cabeçalho (solicitante, data/hora = criado_em, unidade, situação calculada dos itens);
 * - solicitacoes_material_itens: produto (código do SB1 + cópia da descrição/UM do momento), centro de custo,
 *   quantidade, prioridade, observação/utilização e a análise (quantidade aprovada, quem e quando).
 * Só CRIA tabelas: nenhum registro existente é alterado. Sem cascata.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sqlsrv = DB::getDriverName() === 'sqlsrv';

        Schema::create('solicitacoes_material', function (Blueprint $table) {
            $table->id();                                         // nº da solicitação
            $table->string('unidade', 20);                        // manutencao | tecnologia (quem aprova)
            $table->foreignId('solicitante_id')->constrained('usuarios');
            $table->string('status', 12)->default('pendente');    // pendente | em_analise | analisada | cancelada
            $table->string('observacao', 1000)->nullable();       // observação geral (opcional)
            $table->dateTime('criado_em')->nullable();            // data e hora da solicitação (UTC)
            $table->foreignId('criado_por_id')->nullable()->constrained('usuarios');
            $table->dateTime('atualizado_em')->nullable();
            $table->foreignId('atualizado_por_id')->nullable()->constrained('usuarios');
            $table->index(['unidade', 'status', 'id']);           // fila de aprovação da área
            $table->index(['solicitante_id', 'id']);              // "minhas solicitações"
        });

        Schema::create('solicitacoes_material_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('solicitacao_material_id')->constrained('solicitacoes_material');
            $table->string('produto_codigo', 30);                 // B1_COD
            $table->string('produto_descricao', 120)->default(''); // cópia do B1_DESC no momento do pedido
            $table->string('um', 4)->default('');                 // cópia do B1_UM
            $table->string('centro_custo', 20);                   // CTT_CUSTO
            $table->decimal('quantidade_solicitada', 14, 4);
            $table->string('prioridade', 10)->default('media');   // alta | media | baixa
            $table->string('observacao', 1000)->nullable();       // observação / utilização
            $table->string('status', 12)->default('pendente');    // pendente | aprovado | reprovado
            $table->decimal('quantidade_aprovada', 14, 4)->nullable();
            $table->string('motivo_reprovacao', 500)->nullable();
            $table->foreignId('aprovado_por_id')->nullable()->constrained('usuarios'); // quem analisou (aprovou ou reprovou)
            $table->dateTime('aprovado_em')->nullable();          // data e hora da análise (UTC)
            $table->dateTime('criado_em')->nullable();
            $table->foreignId('criado_por_id')->nullable()->constrained('usuarios');
            $table->dateTime('atualizado_em')->nullable();
            $table->foreignId('atualizado_por_id')->nullable()->constrained('usuarios');
            $table->index(['solicitacao_material_id', 'id']);
            $table->index(['status', 'prioridade']);
            $table->index('produto_codigo');
        });

        if ($sqlsrv) {
            foreach ([
                "ALTER TABLE solicitacoes_material ADD CONSTRAINT ck_solicitacoes_material_unidade CHECK (unidade IN ('manutencao', 'tecnologia'))",
                "ALTER TABLE solicitacoes_material ADD CONSTRAINT ck_solicitacoes_material_status CHECK (status IN ('pendente', 'em_analise', 'analisada', 'cancelada'))",
                "ALTER TABLE solicitacoes_material_itens ADD CONSTRAINT ck_solicitacoes_material_itens_prioridade CHECK (prioridade IN ('alta', 'media', 'baixa'))",
                "ALTER TABLE solicitacoes_material_itens ADD CONSTRAINT ck_solicitacoes_material_itens_status CHECK (status IN ('pendente', 'aprovado', 'reprovado'))",
                'ALTER TABLE solicitacoes_material_itens ADD CONSTRAINT ck_solicitacoes_material_itens_quantidades CHECK (quantidade_solicitada > 0 AND (quantidade_aprovada IS NULL OR quantidade_aprovada >= 0))',
            ] as $sql) {
                DB::statement($sql);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitacoes_material_itens');
        Schema::dropIfExists('solicitacoes_material');
    }
};
