<?php

use App\Support\ProceduresBanco;
use Illuminate\Database\Migrations\Migration;

/*
 * dbo.sp_sincronizar_pedidos_venda passa a mexer só nos pedidos dos últimos 6 meses (C5_EMISSAO); os mais antigos
 * ficam no portal como histórico. Com a tabela vazia, faz a carga geral. Reinstala a procedure (SQL Server); não mexe
 * em registros.
 */
return new class extends Migration
{
    public function up(): void
    {
        ProceduresBanco::instalar('sp_sincronizar_pedidos_venda');
    }

    public function down(): void
    {
        // A versão anterior (tabela inteira) não é reinstalada: a procedure fica como está.
    }
};
