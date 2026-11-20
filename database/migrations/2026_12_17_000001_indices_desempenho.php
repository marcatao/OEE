<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Índices de desempenho para a produção (auditoria antes da publicação). Só acrescenta índices; não mexe em registros.
 * - apontamentos_estado (inicio): lista "Apontamento real" ordenada pelo início, sem filtro de máquina.
 * - apontamentos_estado (legenda_id, inicio): pendências "parada sem motivo" (legenda 98) da Timeline e do app.
 * - apontamentos_estado em aberto (fim IS NULL), só no SQL Server (índice filtrado): Timeline, app e status das OPs.
 * - solicitacoes_servico (apontamento_estado_id): SS automática confere a cada apontamento de legenda que abre SS.
 * apontamentos_estado é grande: rode fora do horário de produção.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apontamentos_estado', function (Blueprint $t) {
            if (! Schema::hasIndex('apontamentos_estado', 'ix_apontamentos_estado_inicio')) {
                $t->index('inicio', 'ix_apontamentos_estado_inicio');
            }
            if (! Schema::hasIndex('apontamentos_estado', 'ix_apontamentos_estado_legenda_inicio')) {
                $t->index(['legenda_id', 'inicio'], 'ix_apontamentos_estado_legenda_inicio');
            }
        });
        if (Schema::hasColumn('solicitacoes_servico', 'apontamento_estado_id') && ! Schema::hasIndex('solicitacoes_servico', 'ix_solicitacoes_servico_apontamento')) {
            Schema::table('solicitacoes_servico', fn (Blueprint $t) => $t->index('apontamento_estado_id', 'ix_solicitacoes_servico_apontamento'));
        }

        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement("IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'ix_apontamentos_estado_abertos' AND object_id = OBJECT_ID('apontamentos_estado'))
                CREATE INDEX ix_apontamentos_estado_abertos ON apontamentos_estado (maquina_id) INCLUDE (inicio, legenda_id, ordem_producao_id) WHERE fim IS NULL");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement("IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'ix_apontamentos_estado_abertos' AND object_id = OBJECT_ID('apontamentos_estado'))
                DROP INDEX ix_apontamentos_estado_abertos ON apontamentos_estado");
        }
        Schema::table('apontamentos_estado', function (Blueprint $t) {
            $t->dropIndex('ix_apontamentos_estado_inicio');
            $t->dropIndex('ix_apontamentos_estado_legenda_inicio');
        });
        if (Schema::hasIndex('solicitacoes_servico', 'ix_solicitacoes_servico_apontamento')) {
            Schema::table('solicitacoes_servico', fn (Blueprint $t) => $t->dropIndex('ix_solicitacoes_servico_apontamento'));
        }
    }
};
