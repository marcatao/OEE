<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Máquinas passam a ser uma tabela MISTA com o Protheus (tabela SHB, centros de trabalho):
 * - codigo, nome e centro_custo vêm da SHB (HB_COD, HB_NOME, HB_CC) na primeira sincronização;
 * - o resto (linha, ciclo ideal, paradas, IoT, situação) é do sistema OEE.
 * Máquina nova do Protheus chega INATIVA e sem linha; o usuário completa o cadastro e ativa.
 * Depois de sincronizada, a sincronização só mexe na máquina se ela for excluída no Protheus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maquinas', function (Blueprint $table) {
            $table->string('hb_filial', 8)->nullable();          // HB_FILIAL de origem (vazio = cadastrada no OEE)
            $table->integer('r_e_c_n_o_')->nullable();           // R_E_C_N_O_ da SHB (vazio = cadastrada no OEE)
            $table->char('d_e_l_e_t_', 1)->default(' ');         // '*' = excluída no Protheus
            $table->dateTime('sincronizado_em')->nullable();     // quando veio (ou foi vinculada) do Protheus
            $table->dateTime('excluida_protheus_em')->nullable(); // quando a sincronização viu a exclusão
            $table->index(['hb_filial', 'r_e_c_n_o_']);
        });

        // Máquina vinda do Protheus chega sem linha: linha_id passa a aceitar vazio (exigido só para ativar).
        Schema::table('maquinas', function (Blueprint $table) {
            $table->dropForeign(['linha_id']);
        });
        Schema::table('maquinas', function (Blueprint $table) {
            $table->unsignedBigInteger('linha_id')->nullable()->change();
        });
        Schema::table('maquinas', function (Blueprint $table) {
            $table->foreign('linha_id')->references('id')->on('linhas');
        });

        // Resultado das sincronizações: quantos registros foram marcados como excluídos no Protheus.
        Schema::table('sincronizacoes', function (Blueprint $table) {
            $table->integer('excluidos')->default(0);
        });

        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement("ALTER TABLE maquinas ADD CONSTRAINT ck_maquinas_d_e_l_e_t_ CHECK (d_e_l_e_t_ IN (' ', '*'))");
            // Máquina ativa precisa de linha; máquina excluída no Protheus não pode estar ativa.
            DB::statement('ALTER TABLE maquinas ADD CONSTRAINT ck_maquinas_ativa_com_linha CHECK (ativo = 0 OR linha_id IS NOT NULL)');
            DB::statement("ALTER TABLE maquinas ADD CONSTRAINT ck_maquinas_ativa_nao_excluida CHECK (ativo = 0 OR d_e_l_e_t_ <> '*')");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement('ALTER TABLE maquinas DROP CONSTRAINT ck_maquinas_d_e_l_e_t_');
            DB::statement('ALTER TABLE maquinas DROP CONSTRAINT ck_maquinas_ativa_com_linha');
            DB::statement('ALTER TABLE maquinas DROP CONSTRAINT ck_maquinas_ativa_nao_excluida');
        }
        Schema::table('sincronizacoes', function (Blueprint $table) {
            $table->dropColumn('excluidos');
        });
        Schema::table('maquinas', function (Blueprint $table) {
            $table->dropIndex(['hb_filial', 'r_e_c_n_o_']);
            $table->dropColumn(['hb_filial', 'r_e_c_n_o_', 'd_e_l_e_t_', 'sincronizado_em', 'excluida_protheus_em']);
        });
        // linha_id continua aceitando vazio no down(): voltar a NOT NULL falharia se houver máquina sem linha.
    }
};
