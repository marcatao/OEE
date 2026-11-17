<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Usuários: ligação opcional com o usuário do Protheus, informada pelo Administrador de Tecnologia em
 * Segurança › Usuários.
 * - usuario_protheus: nome de login do usuário no Protheus (ex.: JSILVA).
 * - id_protheus: código do usuário no Protheus (ex.: 000123).
 * Só ACRESCENTA colunas (vazias): nenhum registro existente é alterado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->string('usuario_protheus', 25)->nullable();
            $table->string('id_protheus', 20)->nullable();
            $table->index('usuario_protheus');
            $table->index('id_protheus');
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropIndex(['usuario_protheus']);
            $table->dropIndex(['id_protheus']);
            $table->dropColumn(['usuario_protheus', 'id_protheus']);
        });
    }
};
