<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Periodicidade da sincronização de cada tabela do Protheus (Segurança › Banco de dados): "a cada HH:MM, a partir das
 * HH:MM" de cada dia. O comando protheus:sincronizar-agendadas (agendador do Laravel, a cada minuto) roda as que
 * estão na hora. chave = a de config/protheus.php (sincronizacoes).
 * Só CRIA a tabela: nenhum registro existente é alterado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agendas_sincronizacao', function (Blueprint $table) {
            $table->id();
            $table->string('chave', 40)->unique();            // tabela sincronizada (config/protheus.php)
            $table->boolean('ativo')->default(false);
            $table->integer('intervalo_minutos')->default(60); // "a cada" (5 min a 24 h)
            $table->char('inicio', 5)->default('00:00');      // "a partir das" HH:MM, horário local
            $table->dateTime('ultimo_horario')->nullable();   // último horário da agenda já disparado (UTC)
            $table->dateTime('criado_em')->nullable();
            $table->foreignId('criado_por_id')->nullable()->constrained('usuarios');
            $table->dateTime('atualizado_em')->nullable();
            $table->foreignId('atualizado_por_id')->nullable()->constrained('usuarios');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agendas_sincronizacao');
    }
};
