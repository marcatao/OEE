<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Dados de perfil do funcionário. No login eles são preenchidos com o que o Microsoft Entra ID tiver (setor, cargo,
 * e-mail, celular, matrícula e foto) e depois o próprio funcionário pode ajustar em "Meu perfil".
 * A matrícula vem só do Entra ID (somente leitura no portal).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->string('setor', 120)->nullable();
            $table->string('cargo', 120)->nullable();
            $table->string('email_contato', 320)->nullable();
            $table->string('celular', 40)->nullable();
            $table->string('matricula', 60)->nullable();
            $table->string('foto', 255)->nullable(); // caminho no disco "local" (storage/app/private/fotos)
            $table->dateTime('foto_atualizada_em')->nullable();
            $table->dateTime('perfil_entra_em')->nullable(); // última leitura dos dados do Entra ID
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropColumn(['setor', 'cargo', 'email_contato', 'celular', 'matricula', 'foto', 'foto_atualizada_em', 'perfil_entra_em']);
        });
    }
};
