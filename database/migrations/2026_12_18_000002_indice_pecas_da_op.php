<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Soma das peças da OP (PecasProduzidas::recalcularOp, a cada aviso do Integrador MQTT): índice filtrado só no SQL
 * Server, com as peças incluídas, para o SUM não ler a tabela. Só acrescenta um índice; rode fora do horário de produção.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement("IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'ix_apontamentos_estado_op_pecas' AND object_id = OBJECT_ID('apontamentos_estado'))
                CREATE INDEX ix_apontamentos_estado_op_pecas ON apontamentos_estado (ordem_producao_id) INCLUDE (pecas) WHERE pecas IS NOT NULL");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement("IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'ix_apontamentos_estado_op_pecas' AND object_id = OBJECT_ID('apontamentos_estado'))
                DROP INDEX ix_apontamentos_estado_op_pecas ON apontamentos_estado");
        }
    }
};
