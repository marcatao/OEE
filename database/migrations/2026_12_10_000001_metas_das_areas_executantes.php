<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Metas de tempo das áreas executantes (em MINUTOS; na tela, horas:minutos):
 * - meta_atendimento_min: da abertura do chamado ao 1º apontamento de horas;
 * - meta_reparo_min: do 1º apontamento de horas à conclusão da OS.
 * Só ACRESCENTA colunas (vazias = sem meta).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('os_areas_executantes', function (Blueprint $table) {
            $table->integer('meta_atendimento_min')->nullable();
            $table->integer('meta_reparo_min')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('os_areas_executantes', fn (Blueprint $t) => $t->dropColumn(['meta_atendimento_min', 'meta_reparo_min']));
    }
};
