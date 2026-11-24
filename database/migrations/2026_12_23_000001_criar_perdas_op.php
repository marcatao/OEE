<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Apontamento de perdas da OP (pedido do usuário em 24/11/2026). Só ACRESCENTA (tabela nova e colunas vazias).
 * - perdas_op: cada perda apontada (dia, quem, quantidade, peso, PU usado). Excluir NÃO apaga: grava excluido_em/por
 *   (a lista mostra "Excluído por Fulano") e a perda deixa de contar no total.
 * - ordens_producao.quantidade_perda: soma das perdas não excluídas da OP (campo SÓ do portal; o C2_PERDA do Protheus
 *   nunca muda).
 * - produtos_protheus.b1_peso: B1_PESO (peso unitário, kg) da SB1, preenchido pela sincronização dos produtos.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('perdas_op')) {
            Schema::create('perdas_op', function (Blueprint $t) {
                $t->id();
                $t->foreignId('ordem_producao_id')->constrained('ordens_producao');
                $t->foreignId('maquina_id')->nullable()->constrained('maquinas');
                $t->date('dia');                                  // dia da perda (local)
                $t->decimal('quantidade', 18, 4);                 // peças
                $t->decimal('peso', 18, 4)->nullable();           // kg
                $t->decimal('peso_unitario', 18, 6)->nullable();  // PU (kg por peça) usado na conta (B1_PESO na hora)
                $t->string('origem', 12)->default('portal');      // portal | aplicativo
                $t->string('observacao', 500)->nullable();
                $t->dateTime('excluido_em')->nullable();
                $t->foreignId('excluido_por_id')->nullable()->constrained('usuarios');
                $t->dateTime('criado_em')->nullable();
                $t->foreignId('criado_por_id')->nullable()->constrained('usuarios');
                $t->dateTime('atualizado_em')->nullable();
                $t->foreignId('atualizado_por_id')->nullable()->constrained('usuarios');
                $t->index(['ordem_producao_id', 'excluido_em']);
            });
        }
        if (! Schema::hasColumn('ordens_producao', 'quantidade_perda')) {
            Schema::table('ordens_producao', fn (Blueprint $t) => $t->decimal('quantidade_perda', 18, 4)->nullable());
        }
        if (! Schema::hasColumn('produtos_protheus', 'b1_peso')) {
            Schema::table('produtos_protheus', fn (Blueprint $t) => $t->float('b1_peso')->nullable());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('perdas_op');
        Schema::table('ordens_producao', fn (Blueprint $t) => $t->dropColumn('quantidade_perda'));
        Schema::table('produtos_protheus', fn (Blueprint $t) => $t->dropColumn('b1_peso'));
    }
};
