<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Sincronização em etapas: guarda o último R_E_C_N_O_ já processado, para a próxima etapa continuar dali
 * (o botão da tela Banco de dados sincroniza em etapas curtas, sem estourar o tempo limite do IIS).
 * Só ACRESCENTA uma coluna: nenhum registro existente é alterado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sincronizacoes', function (Blueprint $table) {
            $table->bigInteger('ultimo_recno')->nullable(); // último R_E_C_N_O_ lido do Protheus nesta execução
        });
    }

    public function down(): void
    {
        Schema::table('sincronizacoes', fn (Blueprint $t) => $t->dropColumn('ultimo_recno'));
    }
};
