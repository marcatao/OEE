<?php

use App\Support\ProceduresBanco;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Clientes do Protheus: cópia da SA1010 do servidor vinculado [ALUMBRAPRD].[CMT8F4_145045_PR_PD] em "clientes", pela
 * procedure dbo.sp_sincronizar_clientes (MERGE pelo R_E_C_N_O_). Os pedidos de venda ganham "cliente_id" (vínculo
 * com o cadastro, pelo C5_CLIENTE + C5_LOJACLI na filial do pedido ou compartilhada), preenchido pelas procedures.
 * Instala a procedure nova e reinstala a dos pedidos (que liga os pedidos novos). Só ACRESCENTA: tabela nova e uma
 * coluna vazia; nenhum registro existente é alterado aqui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            $table->string('a1_filial', 8)->default('');   // A1_FILIAL (em branco = compartilhado)
            $table->string('a1_cod', 10)->default('');     // A1_COD
            $table->string('a1_loja', 4)->default('');     // A1_LOJA
            $table->string('a1_nome', 60)->default('');    // A1_NOME   (razão social)
            $table->string('a1_nreduz', 40)->default('');  // A1_NREDUZ (nome fantasia)
            $table->char('a1_pessoa', 1)->default('');     // A1_PESSOA (F física, J jurídica)
            $table->char('a1_tipo', 1)->default('');       // A1_TIPO   (F final, R revendedor, S solidário...)
            $table->string('a1_cgc', 20)->default('');     // A1_CGC    (CNPJ/CPF)
            $table->string('a1_inscr', 20)->default('');   // A1_INSCR  (inscrição estadual)
            $table->string('a1_end', 80)->default('');     // A1_END
            $table->string('a1_bairro', 40)->default('');  // A1_BAIRRO
            $table->string('a1_mun', 60)->default('');     // A1_MUN
            $table->char('a1_est', 2)->default('');        // A1_EST
            $table->string('a1_cep', 10)->default('');     // A1_CEP
            $table->string('a1_ddd', 5)->default('');      // A1_DDD
            $table->string('a1_tel', 30)->default('');     // A1_TEL
            $table->string('a1_email', 255)->default('');  // A1_EMAIL
            $table->string('a1_vend', 10)->default('');    // A1_VEND   (vendedor)
            $table->char('a1_msblql', 1)->default('');     // A1_MSBLQL (1 = bloqueado)
            $table->integer('r_e_c_n_o_');                 // R_E_C_N_O_ de origem (chave)
            $table->dateTime('sincronizado_em')->nullable();
            $table->unique('r_e_c_n_o_');
            $table->index(['a1_cod', 'a1_loja']);
            $table->index('a1_nome');
        });

        Schema::table('pedidos_venda', function (Blueprint $table) {
            // Cliente do pedido (clientes.id); sem chave estrangeira no banco: a sincronização apaga clientes excluídos
            // no Protheus e solta os pedidos antes.
            $table->unsignedBigInteger('cliente_id')->nullable();
            $table->index('cliente_id');
        });

        // Só no SQL Server (as tabelas só são alimentadas pelas procedures).
        ProceduresBanco::instalar('sp_sincronizar_clientes');
        ProceduresBanco::instalar('sp_sincronizar_pedidos_venda');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlsrv') {
            DB::unprepared("IF OBJECT_ID('dbo.sp_sincronizar_clientes', 'P') IS NOT NULL DROP PROCEDURE dbo.sp_sincronizar_clientes");
        }
        Schema::table('pedidos_venda', function (Blueprint $table) {
            $table->dropIndex(['cliente_id']);
            $table->dropColumn('cliente_id');
        });
        Schema::dropIfExists('clientes');
    }
};
