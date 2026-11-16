<?php

use App\Support\ProceduresBanco;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Sincronização das ordens de produção pelo banco de dados:
 * - procedure dbo.sp_sincronizar_ordens_producao (só no SQL Server; texto em database/procedures), que lê a SC2 do
 *   Protheus e grava ordens_producao com MERGE, pelo R_E_C_N_O_. O PHP só a chama (SincronizadorProtheus).
 * - índice em ordens_producao.r_e_c_n_o_ (a chave da procedure).
 * Só ACRESCENTA estrutura: nenhum registro existente é apagado ou alterado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordens_producao', function (Blueprint $table) {
            $table->index('r_e_c_n_o_');
        });

        // Só no SQL Server (no SQLite dos testes a sincronização das OPs segue pelo PHP).
        ProceduresBanco::instalar('sp_sincronizar_ordens_producao');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlsrv') {
            DB::unprepared("IF OBJECT_ID('dbo.sp_sincronizar_ordens_producao', 'P') IS NOT NULL
                DROP PROCEDURE dbo.sp_sincronizar_ordens_producao");
        }
        Schema::table('ordens_producao', function (Blueprint $table) {
            $table->dropIndex(['r_e_c_n_o_']);
        });
    }
};
