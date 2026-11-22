<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Peças produzidas pelo IoT (pedido do usuário em 22/11/2026). Só ACRESCENTA colunas (vazias); nenhum registro existente
 * é alterado.
 * - apontamentos_estado.ciclos: pulsos do contador do canal de produção dentro do apontamento de produção (gravado pelo
 *   Integrador MQTT); cavidades: cavidades ativas do molde da OP usadas na conta (1 sem molde), congeladas na 1ª conta;
 *   pecas = ciclos × cavidades (App\Services\PecasProduzidas).
 * - ordens_producao.quantidade_real: soma das peças dos apontamentos de produção da OP (quantidade real pelo IoT).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apontamentos_estado', function (Blueprint $t) {
            if (! Schema::hasColumn('apontamentos_estado', 'ciclos')) {
                $t->integer('ciclos')->nullable();
            }
            if (! Schema::hasColumn('apontamentos_estado', 'cavidades')) {
                $t->smallInteger('cavidades')->nullable();
            }
            if (! Schema::hasColumn('apontamentos_estado', 'pecas')) {
                $t->integer('pecas')->nullable();
            }
        });
        if (! Schema::hasColumn('ordens_producao', 'quantidade_real')) {
            Schema::table('ordens_producao', fn (Blueprint $t) => $t->decimal('quantidade_real', 18, 2)->nullable());
        }
    }

    public function down(): void
    {
        Schema::table('apontamentos_estado', fn (Blueprint $t) => $t->dropColumn(['ciclos', 'cavidades', 'pecas']));
        Schema::table('ordens_producao', fn (Blueprint $t) => $t->dropColumn('quantidade_real'));
    }
};
