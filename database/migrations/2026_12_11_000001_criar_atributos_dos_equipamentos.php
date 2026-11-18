<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Equipamentos (os_ferramentas): atributos livres, quantos o usuário quiser, cada um com nome e valor
 * (ex.: IP = 10.0.5.20, Versão = 2.1, Porta = 8080). A unidade é a do equipamento.
 * Só ACRESCENTA uma tabela: nenhum registro existente é alterado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('os_ferramentas_atributos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ferramenta_id')->constrained('os_ferramentas');
            $table->string('nome', 60);
            $table->string('valor', 255)->nullable();
            $table->smallInteger('ordem')->default(0);
            $table->dateTime('criado_em')->nullable();
            $table->foreignId('criado_por_id')->nullable()->constrained('usuarios');
            $table->dateTime('atualizado_em')->nullable();
            $table->foreignId('atualizado_por_id')->nullable()->constrained('usuarios');
            $table->index(['ferramenta_id', 'ordem']);
            $table->index('nome');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('os_ferramentas_atributos');
    }
};
