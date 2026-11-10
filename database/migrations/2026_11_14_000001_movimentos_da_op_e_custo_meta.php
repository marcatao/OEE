<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Custos das ordens de produção, com dados do Protheus (banco Compras), só lidos:
 * - movimentos_producao ← SD3 (movimentos internos) com OP: produções (PR), baixas/consumos (RE) e devoluções (DE).
 * - notas_fiscais_op    ← SD2 (itens de nota fiscal de saída) com OP.
 * - ordens_producao.custo_meta: custo unitário do fechamento mais recente do produto na SB9 (a SB9 não é copiada).
 * Só ACRESCENTA tabelas e uma coluna: nenhum registro existente é apagado ou alterado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movimentos_producao', function (Blueprint $table) {
            $table->id();
            $table->string('d3_filial', 8)->default('');   // D3_FILIAL
            $table->string('d3_tm', 3)->default('');       // D3_TM      (tipo de movimento)
            $table->string('d3_cf', 3)->default('');       // D3_CF      (PR0, RE1, DE0...; legenda em config/protheus.php)
            $table->string('d3_cod', 30)->default('');     // D3_COD     (produto movimentado)
            $table->string('d3_um', 2)->default('');       // D3_UM
            $table->float('d3_quant')->default(0);         // D3_QUANT
            $table->string('d3_local', 4)->default('');    // D3_LOCAL   (armazém)
            $table->string('d3_doc', 20)->default('');     // D3_DOC     (documento)
            $table->char('d3_emissao', 8)->default('');    // D3_EMISSAO (AAAAMMDD)
            $table->string('d3_numseq', 10)->default('');  // D3_NUMSEQ  (sequência do movimento)
            $table->float('d3_custo1')->default(0);        // D3_CUSTO1  (valor do movimento, moeda 1)
            $table->char('d3_estorno', 1)->default('');    // D3_ESTORNO ('S' = estornado)
            $table->string('op', 20)->default('');         // campo da OP na SD3 (D3_ORDEM, config protheus.sd3_campo_op)
            $table->string('op_numero', 20)->default('');  // número da OP (C2_NUM + C2_ITEM + C2_SEQUEN) = ordens_producao.numero
            $table->string('tipo', 20)->default('outro');  // calculado pelo portal: producao | consumo | devolucao | outro
            $table->char('d_e_l_e_t_', 1)->default(' ');   // '*' = excluído no Protheus
            $table->integer('r_e_c_n_o_');                 // R_E_C_N_O_ de origem (chave)
            $table->dateTime('sincronizado_em')->nullable();
            $table->unique('r_e_c_n_o_');
            $table->index(['op_numero', 'd3_filial']);
            $table->index('d3_emissao');
        });

        Schema::create('notas_fiscais_op', function (Blueprint $table) {
            $table->id();
            $table->string('d2_filial', 8)->default('');   // D2_FILIAL
            $table->string('d2_doc', 20)->default('');     // D2_DOC     (número da nota)
            $table->string('d2_serie', 3)->default('');    // D2_SERIE
            $table->string('d2_item', 4)->default('');     // D2_ITEM
            $table->string('d2_cod', 30)->default('');     // D2_COD     (produto)
            $table->string('d2_um', 2)->default('');       // D2_UM
            $table->float('d2_quant')->default(0);         // D2_QUANT
            $table->string('d2_local', 4)->default('');    // D2_LOCAL
            $table->char('d2_emissao', 8)->default('');    // D2_EMISSAO (AAAAMMDD)
            $table->float('d2_custo1')->default(0);        // D2_CUSTO1  (custo do item, moeda 1)
            $table->string('d2_cf', 5)->default('');       // D2_CF      (CFOP)
            $table->string('d2_cliente', 10)->default(''); // D2_CLIENTE
            $table->string('d2_loja', 4)->default('');     // D2_LOJA
            $table->string('op', 20)->default('');         // campo da OP na SD2 (D2_OP, config protheus.sd2_campo_op)
            $table->string('op_numero', 20)->default('');  // número da OP = ordens_producao.numero
            $table->char('d_e_l_e_t_', 1)->default(' ');   // '*' = excluído no Protheus
            $table->integer('r_e_c_n_o_');                 // R_E_C_N_O_ de origem (chave)
            $table->dateTime('sincronizado_em')->nullable();
            $table->unique('r_e_c_n_o_');
            $table->index(['op_numero', 'd2_filial']);
            $table->index('d2_emissao');
        });

        Schema::table('ordens_producao', function (Blueprint $table) {
            // Custo unitário do fechamento mais recente do produto (SB9), gravado a cada sincronização das OPs.
            $table->float('custo_meta')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ordens_producao', fn (Blueprint $t) => $t->dropColumn('custo_meta'));
        Schema::dropIfExists('notas_fiscais_op');
        Schema::dropIfExists('movimentos_producao');
    }
};
