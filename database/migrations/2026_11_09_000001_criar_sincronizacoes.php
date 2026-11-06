<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Histórico das sincronizações com o Protheus: uma linha por execução (botão, comando ou agendamento).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sincronizacoes', function (Blueprint $table) {
            $table->id();
            $table->string('chave', 40);                  // item de config/protheus.php (ex.: centros_custo)
            $table->string('origem', 160);                // de onde veio (ex.: Compras.CTT)
            $table->string('disparo', 20);                // manual | agendado | comando
            $table->foreignId('usuario_id')->nullable()->constrained('usuarios'); // quem clicou (manual)
            $table->dateTime('iniciado_em');
            $table->dateTime('terminado_em')->nullable();
            $table->string('situacao', 20);               // executando | sucesso | erro
            $table->integer('lidos')->default(0);
            $table->integer('novos')->default(0);
            $table->integer('atualizados')->default(0);
            $table->integer('ignorados')->default(0);
            $table->string('mensagem', 1000)->nullable(); // erro, se houver
            $table->index(['chave', 'iniciado_em']);
        });

        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement("ALTER TABLE sincronizacoes ADD CONSTRAINT ck_sincronizacoes_situacao CHECK (situacao IN ('executando', 'sucesso', 'erro'))");
            DB::statement("ALTER TABLE sincronizacoes ADD CONSTRAINT ck_sincronizacoes_disparo CHECK (disparo IN ('manual', 'agendado', 'comando'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sincronizacoes');
    }
};
