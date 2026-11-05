<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Dados das máquinas: apontamentos de produção e paradas, a base do cálculo de OEE.
 * Datas sempre em UTC.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apontamentos', function (Blueprint $table) {
            $table->id(); // BIGINT IDENTITY
            $table->foreignId('maquina_id')->constrained('maquinas');
            $table->foreignId('turno_id')->nullable()->constrained('turnos');
            $table->foreignId('produto_id')->nullable()->constrained('produtos');
            $table->dateTime('inicio');
            $table->dateTime('fim');
            $table->float('ciclo_ideal_s'); // copiado do cadastro: o histórico não muda se o cadastro mudar
            $table->integer('total_produzido');
            $table->integer('pecas_boas');
            $table->dateTime('criado_em')->nullable();
            $table->foreignId('criado_por_id')->nullable()->constrained('usuarios');
            $table->dateTime('atualizado_em')->nullable();
            $table->foreignId('atualizado_por_id')->nullable()->constrained('usuarios');
            $table->index(['maquina_id', 'inicio']);
        });

        Schema::create('paradas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('maquina_id')->constrained('maquinas');
            $table->foreignId('motivo_id')->nullable()->constrained('motivos_parada'); // vazio = sem justificativa
            $table->dateTime('inicio');
            $table->dateTime('fim')->nullable(); // vazio = parada em andamento
            $table->string('observacao', 500)->nullable();
            $table->dateTime('criado_em')->nullable();
            $table->foreignId('criado_por_id')->nullable()->constrained('usuarios');
            $table->dateTime('atualizado_em')->nullable();
            $table->foreignId('atualizado_por_id')->nullable()->constrained('usuarios');
            $table->index(['maquina_id', 'inicio']);
        });

        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement('ALTER TABLE apontamentos ADD CONSTRAINT ck_apontamentos_periodo CHECK (fim > inicio)');
            DB::statement('ALTER TABLE apontamentos ADD CONSTRAINT ck_apontamentos_pecas CHECK (pecas_boas >= 0 AND pecas_boas <= total_produzido)');
            DB::statement('ALTER TABLE paradas ADD CONSTRAINT ck_paradas_periodo CHECK (fim IS NULL OR fim > inicio)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('paradas');
        Schema::dropIfExists('apontamentos');
    }
};
