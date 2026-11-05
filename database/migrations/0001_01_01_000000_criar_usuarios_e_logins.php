<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * O Entra ID só autentica a entrada. O usuário é cadastrado aqui no primeiro login (nome e e-mail vindos do
 * Entra ID) e nasce SEM ACESSO: um administrador define o perfil e os grupos. Os grupos dão as permissões de tela.
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
            $table->string('perfil', 20)->default('sem_acesso'); // sem_acesso | operador | admin
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

        Schema::create('grupos', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 80)->unique();
            $table->string('descricao', 300)->nullable();
            $table->boolean('ativo')->default(true);
            $table->dateTime('criado_em')->nullable();
            $table->foreignId('criado_por_id')->nullable()->constrained('usuarios');
            $table->dateTime('atualizado_em')->nullable();
            $table->foreignId('atualizado_por_id')->nullable()->constrained('usuarios');
        });

        // Usuários de cada grupo
        Schema::create('grupo_usuario', function (Blueprint $table) {
            $table->foreignId('grupo_id')->constrained('grupos');
            $table->foreignId('usuario_id')->constrained('usuarios');
            $table->primary(['grupo_id', 'usuario_id']);
        });

        // Telas que cada grupo pode acessar (catálogo em config/oee.php, chave "permissoes")
        Schema::create('grupo_permissoes', function (Blueprint $table) {
            $table->foreignId('grupo_id')->constrained('grupos');
            $table->string('permissao', 60);
            $table->primary(['grupo_id', 'permissao']);
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
            DB::statement("ALTER TABLE usuarios ADD CONSTRAINT ck_usuarios_perfil CHECK (perfil IN ('sem_acesso', 'operador', 'admin'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('grupo_permissoes');
        Schema::dropIfExists('grupo_usuario');
        Schema::dropIfExists('grupos');
        Schema::dropIfExists('registros_login');
        Schema::dropIfExists('usuarios');
    }
};
