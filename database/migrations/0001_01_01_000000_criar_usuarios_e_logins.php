<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * O Entra ID só autentica a entrada. Nome, e-mail, perfil e histórico de logins ficam aqui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usuarios', function (Blueprint $table) {
            $table->id();
            $table->string('entra_oid', 36)->unique();         // ID do objeto do usuário no Entra ID
            $table->string('entra_tenant_id', 36)->nullable();
            $table->string('nome', 200)->nullable();
            $table->string('email', 320)->nullable()->index();
            $table->string('perfil', 20)->default('operador'); // operador < supervisor < admin
            $table->boolean('ativo')->default(true);
            $table->dateTime('ultimo_login_em')->nullable();
            $table->dateTime('criado_em')->nullable();
            $table->dateTime('atualizado_em')->nullable();
        });

        Schema::create('registros_login', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->nullable()->constrained('usuarios');
            $table->string('email', 320)->nullable();
            $table->dateTime('data_hora')->index();
            $table->boolean('sucesso');
            $table->string('motivo', 200)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('navegador', 400)->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement("ALTER TABLE usuarios ADD CONSTRAINT ck_usuarios_perfil CHECK (perfil IN ('operador', 'supervisor', 'admin'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('registros_login');
        Schema::dropIfExists('usuarios');
    }
};
