<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Milissegundos (pedido do usuário em 23/11/2026: máquinas que fazem mais de uma peça por segundo). Só ACRESCENTA
 * colunas vazias; nenhum registro existente é alterado.
 * - maquinas.micro_parada_ms / macro_parada_ms: micro e macro parada em milissegundos (0,5 s = 500). Quando vazias,
 *   valem as colunas antigas em segundos (micro_parada_s / macro_parada_s), que continuam sendo gravadas
 *   (arredondadas) para as versões antigas do Integrador MQTT.
 * - apontamentos_estado.ciclo_medio_ms: ciclo médio do apontamento em milissegundos (gravado pelo Integrador MQTT);
 *   vazio = vale o ciclo_medio antigo (segundos inteiros, 0 a 999).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maquinas', function (Blueprint $t) {
            if (! Schema::hasColumn('maquinas', 'micro_parada_ms')) {
                $t->integer('micro_parada_ms')->nullable();
            }
            if (! Schema::hasColumn('maquinas', 'macro_parada_ms')) {
                $t->integer('macro_parada_ms')->nullable();
            }
        });
        if (! Schema::hasColumn('apontamentos_estado', 'ciclo_medio_ms')) {
            Schema::table('apontamentos_estado', fn (Blueprint $t) => $t->integer('ciclo_medio_ms')->nullable());
            if (DB::getDriverName() === 'sqlsrv') {
                DB::statement('ALTER TABLE apontamentos_estado ADD CONSTRAINT ck_apontamentos_estado_ciclo_medio_ms CHECK (ciclo_medio_ms IS NULL OR ciclo_medio_ms >= 0)');
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('apontamentos_estado', 'ciclo_medio_ms')) {
            if (DB::getDriverName() === 'sqlsrv') {
                DB::statement('ALTER TABLE apontamentos_estado DROP CONSTRAINT ck_apontamentos_estado_ciclo_medio_ms');
            }
            Schema::table('apontamentos_estado', fn (Blueprint $t) => $t->dropColumn('ciclo_medio_ms'));
        }
        Schema::table('maquinas', fn (Blueprint $t) => $t->dropColumn(['micro_parada_ms', 'macro_parada_ms']));
    }
};
