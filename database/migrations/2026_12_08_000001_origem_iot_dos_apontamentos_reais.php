<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Origem IoT do apontamento real (pedido do usuário em 18/11/2026): o Integrador MQTT grava, em cada apontamento
 * automático dele, o dispositivo (dispositivos_iot) e o canal do contador de produção (ex.: "chd1_value").
 * Apontamento manual fica com as duas vazias. Só o integrador grava; o formulário do portal não altera.
 * Só ACRESCENTA as colunas: nenhum registro existente é alterado.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('apontamentos_estado', 'iot_dispositivo_id')) {
            Schema::table('apontamentos_estado', function (Blueprint $table) {
                $table->foreignId('iot_dispositivo_id')->nullable()->constrained('dispositivos_iot');
            });
        }
        if (! Schema::hasColumn('apontamentos_estado', 'iot_canal')) {
            Schema::table('apontamentos_estado', function (Blueprint $table) {
                $table->string('iot_canal', 20)->nullable(); // chd1_value … chd6_value
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('apontamentos_estado', 'iot_canal')) {
            Schema::table('apontamentos_estado', fn (Blueprint $t) => $t->dropColumn('iot_canal'));
        }
        if (Schema::hasColumn('apontamentos_estado', 'iot_dispositivo_id')) {
            Schema::table('apontamentos_estado', function (Blueprint $table) {
                $table->dropConstrainedForeignId('iot_dispositivo_id');
            });
        }
    }
};
