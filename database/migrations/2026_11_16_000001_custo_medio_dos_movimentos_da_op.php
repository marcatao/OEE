<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Custo médio de estoque do item (B2_CM1 da SB2 do Protheus, só lido) em cada movimento de OP, gravado a cada
 * sincronização. Usado para valorizar o movimento quando o Protheus ainda não gravou o custo (D3_CUSTO1 / D2_CUSTO1
 * zerado até o recálculo do custo médio). A SB2 não é copiada.
 * Só ACRESCENTA colunas: nenhum registro existente é alterado.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['movimentos_producao', 'notas_fiscais_op'] as $tabela) {
            Schema::table($tabela, function (Blueprint $table) {
                $table->float('custo_medio')->nullable(); // custo médio unitário do item no estoque (B2_CM1)
            });
        }
    }

    public function down(): void
    {
        foreach (['movimentos_producao', 'notas_fiscais_op'] as $tabela) {
            Schema::table($tabela, fn (Blueprint $t) => $t->dropColumn('custo_medio'));
        }
    }
};
