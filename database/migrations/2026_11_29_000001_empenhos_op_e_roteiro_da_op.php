<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Dados do Protheus (banco Compras), só lidos:
 * - empenhos_op ← SD4 (empenhos: materiais reservados para cada OP; D4_OP = C2_NUM + C2_ITEM + C2_SEQUEN + C2_ITEMGRD).
 * - ordens_producao.c2_roteiro e c2_revisao ← SC2: roteiro (SG2.G2_CODIGO) e revisão da estrutura (SG1.G1_REVINI..G1_REVFIM)
 *   usados pela OP. Preenchidos a partir da próxima sincronização das OPs.
 * Só ACRESCENTA uma tabela e duas colunas: nenhum registro existente é apagado ou alterado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empenhos_op', function (Blueprint $table) {
            $table->id();
            $table->string('d4_filial', 8)->default('');   // D4_FILIAL
            $table->string('d4_cod', 30)->default('');     // D4_COD     (componente empenhado)
            $table->string('d4_op', 20)->default('');      // D4_OP      (OP como está no Protheus)
            $table->string('op_numero', 20)->default('');  // número da OP (C2_NUM + C2_ITEM + C2_SEQUEN) = ordens_producao.numero
            $table->string('d4_local', 4)->default('');    // D4_LOCAL   (armazém)
            $table->char('d4_data', 8)->default('');       // D4_DATA    (AAAAMMDD)
            $table->float('d4_qtdeori')->default(0);       // D4_QTDEORI (quantidade empenhada original)
            $table->float('d4_quant')->default(0);         // D4_QUANT   (saldo ainda empenhado)
            $table->string('d4_trt', 3)->default('');      // D4_TRT     (sequência na estrutura, = G1_TRT)
            $table->string('d4_lotectl', 20)->default(''); // D4_LOTECTL (lote)
            $table->string('d4_oporig', 20)->default('');  // D4_OPORIG  (OP que originou, nas OPs intermediárias)
            $table->char('d_e_l_e_t_', 1)->default(' ');   // '*' = excluído no Protheus
            $table->integer('r_e_c_n_o_');                 // R_E_C_N_O_ de origem (chave)
            $table->dateTime('sincronizado_em')->nullable();
            $table->unique('r_e_c_n_o_');
            $table->index(['op_numero', 'd4_filial']);
            $table->index('d4_cod');
        });

        Schema::table('ordens_producao', function (Blueprint $table) {
            $table->string('c2_roteiro', 2)->default('');  // C2_ROTEIRO (código do roteiro na SG2)
            $table->string('c2_revisao', 3)->default('');  // C2_REVISAO (revisão da estrutura na SG1)
        });
    }

    public function down(): void
    {
        Schema::table('ordens_producao', fn (Blueprint $t) => $t->dropColumn(['c2_roteiro', 'c2_revisao']));
        Schema::dropIfExists('empenhos_op');
    }
};
