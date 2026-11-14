<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * "Pergunte para Alumbra" (assistente de IA da página inicial, com a Claude):
 * - assistente_conversas: uma conversa por usuário, com as mensagens no formato da API da Claude (JSON), para
 *   continuar a conversa nas próximas perguntas.
 * - assistente_perguntas: cada pergunta feita, com a situação, as etapas (chamadas à Claude), os tokens gastos e o
 *   erro, se houver (auditoria, custo e limite diário por usuário).
 * Só ACRESCENTA tabelas: nenhum registro existente é alterado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistente_conversas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios');
            $table->string('titulo', 120)->nullable();      // começo da 1ª pergunta
            $table->longText('mensagens')->nullable();       // JSON: mensagens no formato da API da Claude
            $table->dateTime('criado_em')->nullable();
            $table->dateTime('atualizado_em')->nullable();
            $table->index(['usuario_id', 'atualizado_em']);
        });

        Schema::create('assistente_perguntas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversa_id')->constrained('assistente_conversas');
            $table->foreignId('usuario_id')->constrained('usuarios');
            $table->string('pergunta', 2000);
            $table->integer('posicao')->default(0);              // nº de mensagens da conversa antes da pergunta
            $table->string('situacao', 12)->default('pendente'); // pendente | respondendo | concluida | erro
            $table->smallInteger('etapas')->default(0);           // chamadas feitas à Claude
            $table->string('ferramentas', 1000)->nullable();     // consultas usadas (nomes, separados por vírgula)
            $table->string('modelo', 60)->nullable();
            $table->integer('tokens_entrada')->default(0);
            $table->integer('tokens_saida')->default(0);
            $table->integer('tokens_cache_leitura')->default(0);
            $table->integer('tokens_cache_escrita')->default(0);
            $table->string('erro', 1000)->nullable();
            $table->dateTime('criado_em')->nullable();
            $table->dateTime('concluida_em')->nullable();
            $table->index(['usuario_id', 'criado_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistente_perguntas');
        Schema::dropIfExists('assistente_conversas');
    }
};
