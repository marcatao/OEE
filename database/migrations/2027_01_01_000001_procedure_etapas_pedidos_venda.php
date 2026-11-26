<?php

use App\Support\ProceduresBanco;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Etapas dos pedidos de venda calculadas no BANCO (pedido do usuário em 26/11/2026: "o portal só chama a procedure"):
 * instala dbo.sp_calcular_etapas_pedidos_venda e reinstala dbo.sp_sincronizar_pedidos_venda, que agora a chama no fim.
 * O passo em PHP depois da sincronização deixou de existir (ele estourava o tempo do IIS). Só procedures: nenhum
 * registro é alterado.
 */
return new class extends Migration
{
    public function up(): void
    {
        ProceduresBanco::instalar('sp_calcular_etapas_pedidos_venda');
        ProceduresBanco::instalar('sp_sincronizar_pedidos_venda');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlsrv') {
            DB::unprepared("IF OBJECT_ID('dbo.sp_calcular_etapas_pedidos_venda', 'P') IS NOT NULL DROP PROCEDURE dbo.sp_calcular_etapas_pedidos_venda");
        }
    }
};
