<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chamado para FERRAMENTA/MOLDE (pedido do usuário em 25/11/2026): a SS e a OS podem apontar, no lugar da máquina, uma
 * ferramenta (SH4, tabela `ferramentas`) — só nas unidades de `oee.os_unidades_ferramenta` (Manutenção e
 * Ferramentaria). Só ACRESCENTA a coluna vazia (os chamados existentes continuam com a máquina deles).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['solicitacoes_servico', 'ordens_servico'] as $tabela) {
            if (! Schema::hasColumn($tabela, 'ferramenta_id')) {
                Schema::table($tabela, function (Blueprint $t) {
                    $t->foreignId('ferramenta_id')->nullable()->constrained('ferramentas');
                    $t->index('ferramenta_id');
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['solicitacoes_servico', 'ordens_servico'] as $tabela) {
            Schema::table($tabela, function (Blueprint $t) {
                $t->dropForeign(['ferramenta_id']);
                $t->dropIndex(['ferramenta_id']);
                $t->dropColumn('ferramenta_id');
            });
        }
    }
};
