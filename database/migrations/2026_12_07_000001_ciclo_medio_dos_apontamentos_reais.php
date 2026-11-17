<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Ciclo médio do apontamento real (pedido do usuário em 17/11/2026): segundos por peça nas últimas 10 alterações do
 * contador do canal IoT de produção da máquina, até o fim do apontamento. Gravado pelo Integrador MQTT (MQTT.dbo.IOT001)
 * nos apontamentos que ele cria; limitado a 3 dígitos (acima de 999 s grava 999). Vazio = sem dados (apontamento
 * manual ou sem leituras suficientes).
 * Só ACRESCENTA a coluna: nenhum registro existente é alterado.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('apontamentos_estado', 'ciclo_medio')) {
            Schema::table('apontamentos_estado', function (Blueprint $table) {
                $table->smallInteger('ciclo_medio')->nullable(); // segundos por peça, 0 a 999
            });
            if (DB::getDriverName() === 'sqlsrv') {
                DB::statement('ALTER TABLE apontamentos_estado ADD CONSTRAINT ck_apontamentos_estado_ciclo_medio CHECK (ciclo_medio IS NULL OR (ciclo_medio >= 0 AND ciclo_medio <= 999))');
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('apontamentos_estado', 'ciclo_medio')) {
            if (DB::getDriverName() === 'sqlsrv') {
                DB::statement('ALTER TABLE apontamentos_estado DROP CONSTRAINT ck_apontamentos_estado_ciclo_medio');
            }
            Schema::table('apontamentos_estado', fn (Blueprint $t) => $t->dropColumn('ciclo_medio'));
        }
    }
};
