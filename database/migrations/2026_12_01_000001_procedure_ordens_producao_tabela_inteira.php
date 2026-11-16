<?php

use App\Support\ProceduresBanco;
use Illuminate\Database\Migrations\Migration;

/*
 * dbo.sp_sincronizar_ordens_producao passa a ser sem parâmetros: um MERGE da tabela inteira Compras.dbo.SC2 →
 * ordens_producao (pelo R_E_C_N_O_), que pode ser rodado direto no banco. Reinstala a procedure com o texto atual de
 * database/procedures. Só SQL Server; não mexe em registros.
 */
return new class extends Migration
{
    public function up(): void
    {
        ProceduresBanco::instalar('sp_sincronizar_ordens_producao');
    }

    public function down(): void
    {
        // A versão anterior (com parâmetros) não é reinstalada: a procedure fica como está.
    }
};
