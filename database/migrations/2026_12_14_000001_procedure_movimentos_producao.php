<?php

use App\Support\ProceduresBanco;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Apontamentos OP pela procedure dbo.sp_sincronizar_movimentos_producao (texto em database/procedures): MERGE da
 * SD3010 do servidor vinculado ALUMBRAPRD, só no período aberto do estoque, apagando os excluídos no Protheus.
 * Só instala a procedure (SQL Server); não mexe em registros — a limpeza dos excluídos acontece quando a
 * sincronização roda.
 */
return new class extends Migration
{
    public function up(): void
    {
        ProceduresBanco::instalar('sp_sincronizar_movimentos_producao');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlsrv') {
            DB::unprepared("IF OBJECT_ID('dbo.sp_sincronizar_movimentos_producao', 'P') IS NOT NULL DROP PROCEDURE dbo.sp_sincronizar_movimentos_producao");
        }
    }
};
