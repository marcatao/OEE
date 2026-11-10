<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Tabelas "tipo Protheus" (cópia, sincronizadas do banco Compras), com os mesmos nomes de campo da origem:
 * - produtos_protheus  ← SB1 (cadastro de produtos). A tabela antiga "produtos" (do Sistema OEE) NÃO é alterada.
 * - ordens_producao    ← SC2 (ordens de produção), mais o campo "status" calculado pelo portal:
 *                        aberta | andamento | fechada | parada.
 * Apontamentos de estado ganham a ligação com a ordem de produção (ordem_producao_id).
 * Só ACRESCENTA tabelas e colunas: nenhum registro existente é apagado ou alterado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produtos_protheus', function (Blueprint $table) {
            $table->id();
            $table->string('b1_filial', 8)->default('');  // B1_FILIAL
            $table->string('b1_cod', 30);                 // B1_COD    (código do produto)
            $table->string('b1_desc', 120)->default('');  // B1_DESC   (descrição)
            $table->string('b1_tipo', 2)->default('');    // B1_TIPO   (PA, PI, MP...)
            $table->string('b1_um', 2)->default('');      // B1_UM     (unidade de medida)
            $table->string('b1_grupo', 10)->default('');  // B1_GRUPO
            $table->char('b1_msblql', 1)->default('2');   // B1_MSBLQL 1=Bloqueado 2=Não bloqueado
            $table->char('d_e_l_e_t_', 1)->default(' ');  // '*' = excluído no Protheus
            $table->integer('r_e_c_n_o_')->nullable();    // R_E_C_N_O_ de origem
            $table->dateTime('sincronizado_em')->nullable();
            $table->unique(['b1_filial', 'b1_cod']);
            $table->index('b1_cod');
        });

        Schema::create('ordens_producao', function (Blueprint $table) {
            $table->id();
            $table->string('c2_filial', 8)->default('');   // C2_FILIAL
            $table->string('c2_num', 10);                  // C2_NUM     (número da OP)
            $table->string('c2_item', 4)->default('');     // C2_ITEM
            $table->string('c2_sequen', 4)->default('');   // C2_SEQUEN
            $table->string('numero', 20);                  // C2_NUM + C2_ITEM + C2_SEQUEN (como o Protheus mostra a OP)
            $table->string('c2_produto', 30)->default(''); // C2_PRODUTO (código do produto, SB1)
            $table->string('c2_local', 4)->default('');    // C2_LOCAL   (armazém)
            $table->string('c2_cc', 20)->default('');      // C2_CC      (centro de custo)
            $table->string('c2_um', 2)->default('');       // C2_UM
            $table->float('c2_quant')->default(0);         // C2_QUANT   (quantidade da OP)
            $table->float('c2_quje')->default(0);          // C2_QUJE    (quantidade já produzida)
            $table->float('c2_perda')->default(0);         // C2_PERDA   (perda)
            $table->char('c2_emissao', 8)->default('');    // C2_EMISSAO (AAAAMMDD)
            $table->char('c2_datpri', 8)->default('');     // C2_DATPRI  (previsão de início)
            $table->char('c2_datprf', 8)->default('');     // C2_DATPRF  (previsão de fim)
            $table->char('c2_datrf', 8)->default('');      // C2_DATRF   (data real de fim = OP encerrada)
            $table->char('c2_tpop', 1)->default('');       // C2_TPOP    F=Firme P=Prevista
            $table->char('c2_status', 1)->default('');     // C2_STATUS  N=Normal U=Suspensa S=Sacramentada
            $table->string('c2_obs', 120)->default('');    // C2_OBS
            $table->char('d_e_l_e_t_', 1)->default(' ');   // '*' = excluída no Protheus
            $table->integer('r_e_c_n_o_')->nullable();     // R_E_C_N_O_ de origem
            $table->string('status', 20)->default('aberta'); // calculado pelo portal: aberta | andamento | fechada | parada
            $table->dateTime('status_em')->nullable();     // quando o status mudou pela última vez
            $table->dateTime('sincronizado_em')->nullable();
            $table->unique(['c2_filial', 'c2_num', 'c2_item', 'c2_sequen']);
            $table->index('numero');
            $table->index(['status', 'c2_datprf']);
            $table->index('c2_produto');
        });

        Schema::table('apontamentos_estado', function (Blueprint $table) {
            $table->foreignId('ordem_producao_id')->nullable()->constrained('ordens_producao');
            $table->index('ordem_producao_id');
        });

        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement("ALTER TABLE ordens_producao ADD CONSTRAINT ck_ordens_producao_status CHECK (status IN ('aberta', 'andamento', 'fechada', 'parada'))");
            DB::statement("ALTER TABLE produtos_protheus ADD CONSTRAINT ck_produtos_protheus_msblql CHECK (b1_msblql IN ('1', '2'))");
        }
    }

    public function down(): void
    {
        Schema::table('apontamentos_estado', function (Blueprint $table) {
            $table->dropForeign(['ordem_producao_id']);
            $table->dropIndex(['ordem_producao_id']);
            $table->dropColumn('ordem_producao_id');
        });
        Schema::dropIfExists('ordens_producao');
        Schema::dropIfExists('produtos_protheus');
    }
};
