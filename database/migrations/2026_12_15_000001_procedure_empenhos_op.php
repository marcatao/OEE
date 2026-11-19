<?php

use App\Support\ProceduresBanco;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Empenhos OP pela procedure dbo.sp_sincronizar_empenhos_op (texto em database/procedures): MERGE da SD4010 inteira
 * do servidor vinculado ALUMBRAPRD, apagando os excluídos no Protheus.
 * Só instala a procedure (SQL Server); não mexe em registros — a limpeza dos excluídos acontece quando a
 * sincronização roda.
 */
return new class extends Migration
{
    public function up(): void
    {
        ProceduresBanco::instalar('sp_sincronizar_empenhos_op');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlsrv') {
            DB::unprepared("IF OBJECT_ID('dbo.sp_sincronizar_empenhos_op', 'P') IS NOT NULL DROP PROCEDURE dbo.sp_sincronizar_empenhos_op");
        }
    }
};
