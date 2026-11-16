<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Aplicativo Android (app-movel/): cada celular em que o usuário entrou recebe um token para falar com a API
 * /api/app. No banco fica só o hash (SHA-256) do token. O token vale enquanto for usado (ver
 * config('oee.app.dias_sem_uso')) e cai no logout do app ou quando o usuário perde o acesso ao portal.
 * Só ACRESCENTA uma tabela: nenhum registro existente é alterado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tokens_aplicativo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios');
            $table->char('token_hash', 64)->unique();
            $table->string('dispositivo', 120)->nullable();   // marca e modelo do celular
            $table->string('versao_app', 20)->nullable();     // versão do APK no login
            $table->dateTime('criado_em')->nullable();
            $table->dateTime('ultimo_uso_em')->nullable();
            $table->dateTime('revogado_em')->nullable();       // logout no app (ou acesso removido)
            $table->index(['usuario_id', 'revogado_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tokens_aplicativo');
    }
};
