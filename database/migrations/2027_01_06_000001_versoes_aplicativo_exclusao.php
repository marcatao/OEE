<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Segurança › Aplicativo: excluir uma versão DESATIVADA apaga o arquivo .apk do servidor (limpeza de versões velhas,
 * pedido do usuário em 27/11/2026); a linha fica como histórico com quem excluiu e quando. Só ACRESCENTA as colunas
 * (vazias = não excluída); nenhum registro é alterado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('versoes_aplicativo', function (Blueprint $t) {
            if (! Schema::hasColumn('versoes_aplicativo', 'excluido_em')) {
                $t->dateTime('excluido_em')->nullable();
                $t->foreignId('excluido_por_id')->nullable()->constrained('usuarios');
            }
        });
    }

    public function down(): void
    {
        Schema::table('versoes_aplicativo', function (Blueprint $t) {
            $t->dropConstrainedForeignId('excluido_por_id');
            $t->dropColumn('excluido_em');
        });
    }
};
