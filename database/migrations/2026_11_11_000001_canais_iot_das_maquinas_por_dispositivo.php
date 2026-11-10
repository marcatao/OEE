<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Canais IoT da máquina passam a apontar para o cadastro de Dispositivos IoT (FK), em vez de um texto livre.
 *
 * SÓ ACRESCENTA colunas. As colunas antigas iot1_id..iot3_id (texto) e os valores já gravados nelas NÃO são
 * apagados nem alterados: a passagem do texto antigo para o dispositivo é decisão do usuário (tela da máquina ou
 * comando próprio, quando ele pedir).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maquinas', function (Blueprint $table) {
            foreach ([1, 2, 3] as $n) {
                $table->foreignId("iot{$n}_dispositivo_id")->nullable()->constrained('dispositivos_iot');
            }
        });
    }

    public function down(): void
    {
        Schema::table('maquinas', function (Blueprint $table) {
            foreach ([1, 2, 3] as $n) {
                $table->dropForeign(["iot{$n}_dispositivo_id"]);
                $table->dropColumn("iot{$n}_dispositivo_id");
            }
        });
    }
};
