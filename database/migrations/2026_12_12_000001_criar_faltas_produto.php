<?php

use App\Support\ProceduresBanco;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Falta de produtos (Produção › Falta de produtos): cópia da ZB5 do Protheus (banco Compras), tabela customizada da
 * Alumbra com os produtos que estão faltando ser produzidos. Só os campos REAIS da ZB5 (ZB5_DESCPR e ZB5_NOME são
 * virtuais no dicionário: a descrição vem da SB1). Chave: R_E_C_N_O_.
 * - solicitado_em / atendido_em: ZB5_DTSOLI + ZB5_HORINI e ZB5_DTATEN + ZB5_HORFIM (horário local do Protheus)
 *   convertidos para UTC, para filtrar e calcular o tempo de atendimento.
 * Sincronizada pela procedure dbo.sp_sincronizar_faltas_produto (MERGE da ZB5 inteira), instalada aqui.
 * Só CRIA a tabela: nenhum registro existente é alterado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faltas_produto', function (Blueprint $table) {
            $table->id();
            $table->string('zb5_filial', 6)->default('');   // ZB5_FILIAL
            $table->string('zb5_doc', 10)->default('');     // ZB5_DOC    (documento da solicitação)
            $table->string('zb5_produt', 16)->default('');  // ZB5_PRODUT (produto que está faltando, SB1)
            $table->float('zb5_qtdsol')->default(0);        // ZB5_QTDSOL (quantidade solicitada)
            $table->float('zb5_qtdate')->default(0);        // ZB5_QTDATE (quantidade atendida)
            $table->string('zb5_pedido', 6)->default('');   // ZB5_PEDIDO (pedido de venda)
            $table->float('zb5_vlped')->default(0);         // ZB5_VLPED  (valor do pedido)
            $table->string('zb5_solici', 19)->default('');  // ZB5_SOLICI (quem solicitou)
            $table->char('zb5_dtsoli', 8)->default('');     // ZB5_DTSOLI (data da solicitação, AAAAMMDD)
            $table->string('zb5_horini', 8)->default('');   // ZB5_HORINI (hora da solicitação, HH:MM:SS)
            $table->char('zb5_dtaten', 8)->default('');     // ZB5_DTATEN (data do atendimento)
            $table->string('zb5_horfim', 8)->default('');   // ZB5_HORFIM (hora do atendimento)
            $table->string('zb5_atendi', 19)->default('');  // ZB5_ATENDI (quem atendeu)
            $table->char('zb5_status', 1)->default('P');    // ZB5_STATUS P=Pendente A=Atendida parcialmente C=Concluída D=Cancelada
            $table->dateTime('solicitado_em')->nullable();  // ZB5_DTSOLI + ZB5_HORINI em UTC
            $table->dateTime('atendido_em')->nullable();    // ZB5_DTATEN + ZB5_HORFIM em UTC
            $table->char('d_e_l_e_t_', 1)->default(' ');    // '*' = excluída no Protheus
            $table->integer('r_e_c_n_o_');                  // R_E_C_N_O_ de origem (chave)
            $table->dateTime('sincronizado_em')->nullable();
            $table->unique('r_e_c_n_o_');
            $table->index('zb5_produt');
            $table->index(['zb5_status', 'solicitado_em']);
            $table->index('atendido_em');
        });

        // Só no SQL Server (no SQLite dos testes a sincronização segue pelo PHP).
        ProceduresBanco::instalar('sp_sincronizar_faltas_produto');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlsrv') {
            DB::unprepared("IF OBJECT_ID('dbo.sp_sincronizar_faltas_produto', 'P') IS NOT NULL DROP PROCEDURE dbo.sp_sincronizar_faltas_produto");
        }
        Schema::dropIfExists('faltas_produto');
    }
};
