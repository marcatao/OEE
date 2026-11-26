<?php

use App\Support\ProceduresBanco;
use Illuminate\Database\Migrations\Migration;

/*
 * dbo.sp_sincronizar_pedidos_venda: se o portal ainda tiver itens sem tipo de operação (gravados antes do filtro de
 * C6_OPER), a próxima execução relê os pedidos de TODOS os períodos uma vez, com o filtro de tipos: o histórico fica só
 * com os tipos aceitos (pedido do usuário). Reinstala a procedure (SQL Server); a migração não mexe em registros —
 * quem atualiza é a sincronização.
 */
return new class extends Migration
{
    public function up(): void
    {
        ProceduresBanco::instalar('sp_sincronizar_pedidos_venda');
    }

    public function down(): void
    {
        // A versão anterior não é reinstalada: a procedure fica como está.
    }
};
