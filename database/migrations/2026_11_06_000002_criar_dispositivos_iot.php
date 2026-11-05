<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Dispositivos IoT (sensores, CLPs, gateways, contadores) que enviam os dados das máquinas.
 * São desativados, nunca excluídos. Datas em UTC.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispositivos_iot', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 40)->unique();
            $table->string('nome', 120);
            $table->string('tipo', 20);       // sensor | clp | gateway | contador | outro
            $table->string('protocolo', 20);  // mqtt | opcua | modbus_tcp | http | outro
            $table->string('endereco', 200)->nullable(); // IP, nome do host ou URL
            $table->integer('porta')->nullable();
            $table->string('topico', 250)->nullable();   // tópico MQTT, nó OPC UA ou registrador Modbus
            $table->foreignId('maquina_id')->nullable()->constrained('maquinas');
            $table->dateTime('ultimo_sinal_em')->nullable();
            $table->string('observacao', 500)->nullable();
            $table->boolean('ativo')->default(true);
            $table->dateTime('criado_em')->nullable();
            $table->foreignId('criado_por_id')->nullable()->constrained('usuarios');
            $table->dateTime('atualizado_em')->nullable();
            $table->foreignId('atualizado_por_id')->nullable()->constrained('usuarios');
            $table->index('maquina_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispositivos_iot');
    }
};
