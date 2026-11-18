<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Aplicativo Android: o mantenedor pode RECUSAR uma SS pendente no alerta. Só aquela SS deixa de tocar para ele (as
 * próximas tocam normalmente); a SS continua pendente para os outros e na lista dele. O histórico da SS registra
 * "SS não aceita por <nome>". Só ACRESCENTA uma tabela: nenhum registro existente é alterado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('os_recusas_aplicativo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('solicitacao_id')->constrained('solicitacoes_servico');
            $table->foreignId('usuario_id')->constrained('usuarios');
            $table->dateTime('criado_em')->nullable();
            $table->unique(['solicitacao_id', 'usuario_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('os_recusas_aplicativo');
    }
};
