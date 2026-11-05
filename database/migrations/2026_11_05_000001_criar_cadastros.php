<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Cadastros: plantas > linhas > máquinas, turnos, produtos e motivos de parada.
 * Registros com histórico são desativados (ativo = 0), nunca excluídos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plantas', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 20)->unique();
            $table->string('nome', 120);
            $table->boolean('ativo')->default(true);
            $this->auditoria($table);
        });

        Schema::create('linhas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('planta_id')->constrained('plantas');
            $table->string('codigo', 20);
            $table->string('nome', 120);
            $table->boolean('ativo')->default(true);
            $this->auditoria($table);
            $table->unique(['planta_id', 'codigo']);
        });

        Schema::create('maquinas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('linha_id')->constrained('linhas');
            $table->string('codigo', 30)->unique();
            $table->string('nome', 120);
            $table->string('fabricante', 120)->nullable();
            $table->string('modelo', 120)->nullable();
            $table->float('ciclo_ideal_s')->nullable(); // padrão da máquina; o apontamento grava o seu
            $table->boolean('ativo')->default(true);
            $this->auditoria($table);
        });

        Schema::create('turnos', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 60)->unique();
            $table->time('inicio');
            $table->time('fim'); // se fim < início, o turno vira a noite
            $table->boolean('ativo')->default(true);
            $this->auditoria($table);
        });

        Schema::create('produtos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 40)->unique();
            $table->string('descricao', 200);
            $table->boolean('ativo')->default(true);
            $this->auditoria($table);
        });

        Schema::create('motivos_parada', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 20)->unique();
            $table->string('descricao', 200);
            $table->string('categoria', 60)->nullable(); // liga o motivo a uma das seis grandes perdas
            $table->boolean('planejada')->default(false); // paradas planejadas não contam na disponibilidade
            $table->boolean('ativo')->default(true);
            $this->auditoria($table);
        });
    }

    private function auditoria(Blueprint $table): void
    {
        $table->dateTime('criado_em')->nullable();
        $table->foreignId('criado_por_id')->nullable()->constrained('usuarios');
        $table->dateTime('atualizado_em')->nullable();
        $table->foreignId('atualizado_por_id')->nullable()->constrained('usuarios');
    }

    public function down(): void
    {
        foreach (['motivos_parada', 'produtos', 'turnos', 'maquinas', 'linhas', 'plantas'] as $tabela) {
            Schema::dropIfExists($tabela);
        }
    }
};
