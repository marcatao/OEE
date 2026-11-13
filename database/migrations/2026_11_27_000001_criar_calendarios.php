<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Calendários de dias úteis (anuais, por unidade; uma unidade pode ter vários). O padrão é segunda a sexta útil;
 * calendario_dias guarda só as exceções: feriados (nacionais e de São Bernardo do Campo - SP já vêm carregados), pontes,
 * folgas, compensações e dias úteis extras.
 * Máquinas e mantenedores escolhem um calendário; a máquina também ganha dias da semana e horário de trabalho (o
 * mantenedor já tinha jornada e dias). Obrigatórios para ativar.
 * Só ACRESCENTA tabelas e colunas: nenhum registro existente é alterado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendarios', function (Blueprint $table) {
            $table->id();
            $table->string('unidade', 20)->default('manutencao');  // manutencao | tecnologia | ferramentaria | ambos
            $table->string('nome', 80);
            $table->smallInteger('ano');
            $table->string('descricao', 300)->nullable();
            $table->boolean('ativo')->default(true);
            $table->dateTime('criado_em')->nullable();
            $table->foreignId('criado_por_id')->nullable()->constrained('usuarios');
            $table->dateTime('atualizado_em')->nullable();
            $table->foreignId('atualizado_por_id')->nullable()->constrained('usuarios');
            $table->unique(['unidade', 'nome', 'ano']);
            $table->index(['unidade', 'ano']);
        });

        Schema::create('calendario_dias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendario_id')->constrained('calendarios');
            $table->date('data');
            $table->string('tipo', 12);              // feriado | ponte | folga (não úteis) · compensacao | util (úteis)
            $table->string('descricao', 120)->nullable();
            $table->dateTime('criado_em')->nullable();
            $table->foreignId('criado_por_id')->nullable()->constrained('usuarios');
            $table->dateTime('atualizado_em')->nullable();
            $table->foreignId('atualizado_por_id')->nullable()->constrained('usuarios');
            $table->unique(['calendario_id', 'data']);
        });

        Schema::table('maquinas', function (Blueprint $table) {
            $table->foreignId('calendario_id')->nullable()->constrained('calendarios');
            $table->string('dias_semana', 7)->nullable();     // ISO: 1 = segunda ... 7 = domingo (ex.: 12345)
            $table->string('jornada_inicio', 5)->nullable();  // HH:MM no fuso local
            $table->string('jornada_fim', 5)->nullable();     // antes do início = vira o dia
        });

        Schema::table('os_mantenedores', function (Blueprint $table) {
            $table->foreignId('calendario_id')->nullable()->constrained('calendarios');
        });

        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement("ALTER TABLE calendarios ADD CONSTRAINT ck_calendarios_unidade CHECK (unidade IN ('manutencao', 'tecnologia', 'ferramentaria', 'ambos'))");
            DB::statement("ALTER TABLE calendario_dias ADD CONSTRAINT ck_calendario_dias_tipo CHECK (tipo IN ('feriado', 'ponte', 'folga', 'compensacao', 'util'))");
        }
    }

    public function down(): void
    {
        Schema::table('os_mantenedores', fn (Blueprint $t) => $t->dropConstrainedForeignId('calendario_id'));
        Schema::table('maquinas', function (Blueprint $t) {
            $t->dropConstrainedForeignId('calendario_id');
            $t->dropColumn(['dias_semana', 'jornada_inicio', 'jornada_fim']);
        });
        Schema::dropIfExists('calendario_dias');
        Schema::dropIfExists('calendarios');
    }
};
