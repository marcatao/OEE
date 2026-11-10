<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Filtros que cada usuário salva nas telas de relatório (começando pela Timeline). Um usuário pode ter vários
 * filtros por tela, com nome, e marcar um como padrão (aplicado ao abrir a tela).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('filtros_usuarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios');
            $table->string('tela', 40);          // ex.: timeline
            $table->string('nome', 80);          // nome dado pelo usuário
            $table->text('filtros');             // JSON com os valores do filtro
            $table->boolean('padrao')->default(false); // aplicado ao abrir a tela
            $table->dateTime('criado_em')->nullable();
            $table->dateTime('atualizado_em')->nullable();
            $table->unique(['usuario_id', 'tela', 'nome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('filtros_usuarios');
    }
};
