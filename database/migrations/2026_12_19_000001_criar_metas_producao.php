<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Histórico das metas de produção (pç/min), pedido do usuário em 23/11/2026: a meta vem do item MO da estrutura do
 * produto (SG1) e, se a estrutura mudar, a meta NÃO pode mudar para trás. Cada mudança da meta de um produto (e revisão
 * da estrutura) ganha uma linha com o início da vigência; o passado usa a meta vigente na época (App\Services\MetaProducao).
 * Só CRIA a tabela: nenhum registro existente é alterado.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('metas_producao')) {
            return;
        }
        Schema::create('metas_producao', function (Blueprint $t) {
            $t->id();
            $t->string('produto', 30);                 // C2_PRODUTO / G1_COD
            $t->string('revisao', 3)->default('');     // C2_REVISAO da OP (estrutura da revisão)
            $t->string('item_mo', 30)->nullable();     // G1_COMP do item tipo MO (null = estrutura ficou sem MO)
            $t->float('horas')->nullable();            // G1_QUANT (horas centesimais por B1_QB peças)
            $t->float('base')->nullable();             // B1_QB (quantidade base)
            $t->float('por_minuto')->nullable();       // meta em pç/min (null = sem meta a partir daqui)
            $t->dateTime('vigente_desde');             // UTC: a partir de quando vale
            $t->dateTime('registrado_em');             // UTC
            $t->index(['produto', 'revisao', 'vigente_desde']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metas_producao');
    }
};
