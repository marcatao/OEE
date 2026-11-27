<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ordens de serviço › Preventivas: botão "Excluir" (pedido do usuário em 27/11/2026). A preventiva excluída some da
 * lista e não gera mais OS, mas a linha fica como histórico (as OS já geradas continuam ligadas a ela), com quem excluiu
 * e quando. Só ACRESCENTA as colunas (vazias = não excluída); nenhum registro é alterado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('os_planos_preventiva', function (Blueprint $t) {
            if (! Schema::hasColumn('os_planos_preventiva', 'excluido_em')) {
                $t->dateTime('excluido_em')->nullable();
                $t->foreignId('excluido_por_id')->nullable()->constrained('usuarios');
            }
        });
    }

    public function down(): void
    {
        Schema::table('os_planos_preventiva', function (Blueprint $t) {
            $t->dropConstrainedForeignId('excluido_por_id');
            $t->dropColumn('excluido_em');
        });
    }
};
