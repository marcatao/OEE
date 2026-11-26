<?php

use App\Support\ProceduresBanco;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Pedidos de venda do Protheus numa tabela só: cada linha é um ITEM do pedido (SC6010) com os dados do cabeçalho
 * (SC5010) e o nome do cliente (SA1010) juntos. Fonte: servidor vinculado [ALUMBRAPRD].[CMT8F4_145045_PR_PD].
 * Chave: R_E_C_N_O_ do item (SC6); c5_recno = R_E_C_N_O_ do cabeçalho.
 * "situacao" é calculada na sincronização: aberto | parcial | faturado | eliminado (resíduo, C6_BLQ = 'R').
 * Sincronizada pela procedure dbo.sp_sincronizar_pedidos_venda (MERGE), instalada aqui.
 * Só CRIA a tabela: nenhum registro existente é alterado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedidos_venda', function (Blueprint $table) {
            $table->id();
            // Cabeçalho (SC5)
            $table->string('c5_filial', 8)->default('');     // C5_FILIAL
            $table->string('c5_num', 10)->default('');       // C5_NUM     (número do pedido)
            $table->char('c5_tipo', 1)->default('');         // C5_TIPO    (N normal, D devolução, B beneficiamento...)
            $table->string('c5_cliente', 10)->default('');   // C5_CLIENTE
            $table->string('c5_lojacli', 4)->default('');    // C5_LOJACLI
            $table->string('cliente_nome', 60)->default(''); // A1_NOME    (SA1, pelo cliente + loja)
            $table->string('c5_condpag', 3)->default('');    // C5_CONDPAG (condição de pagamento)
            $table->char('c5_emissao', 8)->default('');      // C5_EMISSAO (AAAAMMDD)
            $table->string('c5_vend1', 10)->default('');     // C5_VEND1   (vendedor)
            $table->string('c5_transp', 10)->default('');    // C5_TRANSP  (transportadora)
            $table->string('c5_nota', 9)->default('');       // C5_NOTA    (última nota do pedido)
            $table->string('c5_serie', 3)->default('');      // C5_SERIE
            $table->char('c5_liberok', 1)->default('');     // C5_LIBEROK (S = liberado)
            // Item (SC6)
            $table->string('c6_item', 4)->default('');       // C6_ITEM
            $table->string('c6_produto', 30)->default('');   // C6_PRODUTO (SB1)
            $table->string('c6_descri', 60)->default('');    // C6_DESCRI
            $table->string('c6_um', 2)->default('');         // C6_UM
            $table->float('c6_qtdven')->default(0);          // C6_QTDVEN  (quantidade vendida)
            $table->float('c6_prcven')->default(0);          // C6_PRCVEN  (preço unitário)
            $table->float('c6_valor')->default(0);           // C6_VALOR   (valor total do item)
            $table->float('c6_qtdent')->default(0);          // C6_QTDENT  (quantidade já entregue/faturada)
            $table->char('c6_entreg', 8)->default('');       // C6_ENTREG  (data de entrega, AAAAMMDD)
            $table->string('c6_tes', 3)->default('');        // C6_TES
            $table->string('c6_local', 4)->default('');      // C6_LOCAL   (armazém)
            $table->string('c6_nota', 9)->default('');       // C6_NOTA    (nota que faturou o item)
            $table->string('c6_serie', 3)->default('');      // C6_SERIE
            $table->char('c6_datfat', 8)->default('');       // C6_DATFAT  (data do faturamento)
            $table->string('c6_blq', 2)->default('');        // C6_BLQ     (R = resíduo eliminado, S = bloqueado)
            $table->string('c6_numop', 10)->default('');     // C6_NUMOP   (OP gerada para o item)
            $table->string('c6_itemop', 4)->default('');     // C6_ITEMOP
            $table->string('c6_pedcli', 20)->default('');    // C6_PEDCLI  (pedido do cliente)
            // Calculado e chaves
            $table->string('situacao', 12)->default('aberto'); // aberto | parcial | faturado | eliminado
            $table->integer('c5_recno')->nullable();         // R_E_C_N_O_ do cabeçalho (SC5)
            $table->integer('r_e_c_n_o_');                   // R_E_C_N_O_ do item (SC6, chave)
            $table->dateTime('sincronizado_em')->nullable();
            $table->unique('r_e_c_n_o_');
            $table->index(['c5_num', 'c5_filial']);
            $table->index('c6_produto');
            $table->index(['situacao', 'c6_entreg']);
            $table->index('c5_cliente');
        });

        // Só no SQL Server (a tabela só é alimentada pela procedure).
        ProceduresBanco::instalar('sp_sincronizar_pedidos_venda');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlsrv') {
            DB::unprepared("IF OBJECT_ID('dbo.sp_sincronizar_pedidos_venda', 'P') IS NOT NULL DROP PROCEDURE dbo.sp_sincronizar_pedidos_venda");
        }
        Schema::dropIfExists('pedidos_venda');
    }
};
