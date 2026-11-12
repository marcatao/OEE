<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Tabelas do Protheus (banco Compras, só lido), com os mesmos nomes de campo da origem:
 * - estruturas_produto ← SG1 (estrutura / lista de materiais: componentes de cada produto). Cópia.
 * - roteiros_operacao  ← SG2 (roteiro de operações: operação, recurso, centro de trabalho, ferramenta, tempos). Cópia.
 * - ferramentas        ← SH4 (ferramentas). MISTA: os campos h4_* vêm do Protheus a cada sincronização; o portal
 *                        cuida de h4_qtdcavativas (cavidades ativas; nasce igual a H4_QTDCAV, nunca maior, e a
 *                        sincronização não sobrepõe) e da unidade.
 * Só CRIA tabelas: nenhum registro existente é apagado ou alterado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estruturas_produto', function (Blueprint $table) {
            $table->id();
            $table->string('g1_filial', 8)->default('');   // G1_FILIAL
            $table->string('g1_cod', 30);                  // G1_COD    (produto pai)
            $table->string('g1_comp', 30)->default('');    // G1_COMP   (componente)
            $table->string('g1_trt', 3)->default('');      // G1_TRT    (sequência do componente)
            $table->float('g1_quant')->default(0);         // G1_QUANT  (quantidade do componente por unidade do pai)
            $table->float('g1_perda')->default(0);         // G1_PERDA  (% de perda)
            $table->char('g1_ini', 8)->default('');        // G1_INI    (início da validade, AAAAMMDD)
            $table->char('g1_fim', 8)->default('');        // G1_FIM    (fim da validade)
            $table->char('g1_fixvar', 1)->default('');     // G1_FIXVAR (F = quantidade fixa, V = variável)
            $table->string('g1_revini', 3)->default('');   // G1_REVINI (revisão inicial)
            $table->string('g1_revfim', 3)->default('');   // G1_REVFIM (revisão final)
            $table->char('d_e_l_e_t_', 1)->default(' ');   // '*' = excluído no Protheus
            $table->integer('r_e_c_n_o_')->nullable();     // R_E_C_N_O_ de origem
            $table->dateTime('sincronizado_em')->nullable();
            $table->unique(['g1_filial', 'g1_cod', 'g1_comp', 'g1_trt']);
            $table->index('g1_cod');
            $table->index('g1_comp');
        });

        Schema::create('roteiros_operacao', function (Blueprint $table) {
            $table->id();
            $table->string('g2_filial', 8)->default('');   // G2_FILIAL
            $table->string('g2_produto', 30);              // G2_PRODUTO (produto)
            $table->string('g2_codigo', 4)->default('');   // G2_CODIGO  (código do roteiro)
            $table->string('g2_operac', 4)->default('');   // G2_OPERAC  (operação)
            $table->string('g2_descri', 120)->default(''); // G2_DESCRI  (descrição da operação)
            $table->string('g2_recurso', 10)->default(''); // G2_RECURSO (recurso, SH1)
            $table->string('g2_ctrab', 10)->default('');   // G2_CTRAB   (centro de trabalho, SHB = máquina do portal)
            $table->string('g2_ferram', 10)->default('');  // G2_FERRAM  (ferramenta, SH4)
            $table->char('g2_tpoper', 1)->default('');     // G2_TPOPER  (tipo de operação)
            $table->float('g2_setup')->default(0);         // G2_SETUP   (tempo de preparação)
            $table->float('g2_tempad')->default(0);        // G2_TEMPAD  (tempo padrão)
            $table->float('g2_lotepad')->default(0);       // G2_LOTEPAD (lote padrão do tempo padrão)
            $table->float('g2_maoobra')->default(0);       // G2_MAOOBRA (mão de obra)
            $table->char('d_e_l_e_t_', 1)->default(' ');
            $table->integer('r_e_c_n_o_')->nullable();
            $table->dateTime('sincronizado_em')->nullable();
            $table->unique(['g2_filial', 'g2_produto', 'g2_codigo', 'g2_operac']);
            $table->index('g2_produto');
            $table->index('g2_ferram');
            $table->index('g2_ctrab');
        });

        Schema::create('ferramentas', function (Blueprint $table) {
            $table->id();
            $table->string('h4_filial', 8)->default('');   // H4_FILIAL
            $table->string('h4_codigo', 10);               // H4_CODIGO
            $table->string('h4_descri', 120)->default(''); // H4_DESCRI
            $table->float('h4_quant')->default(0);         // H4_QUANT   (quantidade de ferramentas)
            $table->float('h4_vidauti')->default(0);       // H4_VIDAUTI (vida útil)
            $table->char('h4_tipovid', 1)->default('');    // H4_TIPOVID (tipo da vida útil)
            $table->integer('h4_qtdcav')->default(0);      // H4_QTDCAV  (quantidade de cavidades, Protheus)
            $table->integer('h4_qtdcavativas')->default(0); // do PORTAL: cavidades ativas (≤ h4_qtdcav); a sincronização não mexe
            $table->string('unidade', 20)->default('manutencao'); // do PORTAL: manutencao | tecnologia | ambos
            $table->char('d_e_l_e_t_', 1)->default(' ');
            $table->integer('r_e_c_n_o_')->nullable();
            $table->dateTime('sincronizado_em')->nullable();
            $table->dateTime('criado_em')->nullable();
            $table->foreignId('criado_por_id')->nullable()->constrained('usuarios');
            $table->dateTime('atualizado_em')->nullable();
            $table->foreignId('atualizado_por_id')->nullable()->constrained('usuarios');
            $table->unique(['h4_filial', 'h4_codigo']);
            $table->index('h4_codigo');
            $table->index('unidade');
        });

        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement("ALTER TABLE ferramentas ADD CONSTRAINT ck_ferramentas_unidade CHECK (unidade IN ('manutencao', 'tecnologia', 'ambos'))");
            DB::statement('ALTER TABLE ferramentas ADD CONSTRAINT ck_ferramentas_cavidades CHECK (h4_qtdcavativas >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ferramentas');
        Schema::dropIfExists('roteiros_operacao');
        Schema::dropIfExists('estruturas_produto');
    }
};
