<?php

use App\Support\ProceduresBanco;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Faturamento › Pedidos de venda (pedido do usuário em 26/11/2026), sobre a tabela pedidos_venda (SC5 + SC6, uma linha
 * por item) que já existe:
 * - pedidos_venda_liberacoes: cópia da SC9010 (liberações dos pedidos), gravada pela MESMA procedure
 *   dbo.sp_sincronizar_pedidos_venda (MERGE pelo R_E_C_N_O_, mesma janela de 6 meses pelo C5_EMISSAO). "extras" (JSON)
 *   guarda os campos do WMS usados nas regras das etapas, só os que existem na SC9010.
 * - regras_etapa_pedido: condição sobre um campo da SC9 que leva a liberação a uma etapa do WMS (configurada na tela).
 * - pedidos_venda_etapas: uma linha por pedido (filial + número) com a etapa, desde quando, valores e datas, calculados
 *   pelo PHP (App\Services\Faturamento\EtapasPedidoVenda) só com as tabelas do portal.
 * O portal nunca lê o Protheus direto: a procedure (reinstalada aqui) é o único caminho.
 * Só CRIA tabelas: nenhum registro existente é alterado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedidos_venda_liberacoes', function (Blueprint $table) {
            $table->id();
            $table->string('c9_filial', 8)->default('');     // C9_FILIAL
            $table->string('c9_pedido', 10)->default('');    // C9_PEDIDO
            $table->string('c9_item', 4)->default('');       // C9_ITEM
            $table->string('c9_sequen', 3)->default('');     // C9_SEQUEN
            $table->string('c9_produto', 30)->default('');   // C9_PRODUTO
            $table->float('c9_qtdlib')->default(0);          // C9_QTDLIB  (quantidade liberada)
            $table->float('c9_prcven')->default(0);          // C9_PRCVEN
            $table->char('c9_datalib', 8)->default('');      // C9_DATALIB (data da liberação)
            $table->string('c9_blest', 2)->default('');      // C9_BLEST   (bloqueio de estoque; vazio = liberado; 10 = faturado)
            $table->string('c9_blcred', 2)->default('');     // C9_BLCRED  (bloqueio de crédito; vazio = liberado; 10 = faturado)
            $table->string('c9_nfiscal', 9)->default('');    // C9_NFISCAL (nota; preenchida = faturada)
            $table->string('c9_serienf', 3)->default('');    // C9_SERIENF
            $table->text('extras')->nullable();              // campos do WMS usados nas regras (JSON)
            $table->char('c5_emissao', 8)->default('');      // C5_EMISSAO do pedido (janela de 6 meses da procedure)
            $table->integer('r_e_c_n_o_');                   // R_E_C_N_O_ da SC9 (chave)
            $table->dateTime('sincronizado_em')->nullable();
            $table->unique('r_e_c_n_o_');
            $table->index(['c9_pedido', 'c9_filial']);
            $table->index('c5_emissao');
        });

        Schema::create('regras_etapa_pedido', function (Blueprint $table) {
            $table->id();
            $table->string('etapa', 30);                       // separacao | conferencia | logistica | aguardando_faturamento
            $table->string('campo', 10);                       // campo da SC9, ex.: C9_STSERV
            $table->string('operador', 12)->default('igual');  // igual | diferente | comeca | preenchido | vazio
            $table->string('valores', 200)->default('');       // separados por vírgula
            $table->string('observacao', 200)->nullable();
            $table->boolean('ativo')->default(true);
            $table->dateTime('criado_em')->nullable();
            $table->foreignId('criado_por_id')->nullable()->constrained('usuarios');
            $table->dateTime('atualizado_em')->nullable();
            $table->foreignId('atualizado_por_id')->nullable()->constrained('usuarios');
            $table->index(['etapa', 'ativo']);
        });

        Schema::create('pedidos_venda_etapas', function (Blueprint $table) {
            $table->id();
            $table->string('c5_filial', 8)->default('');
            $table->string('c5_num', 10)->default('');
            $table->string('c5_cliente', 10)->default('');     // do pedido (pedidos_venda), para a lista
            $table->string('c5_lojacli', 4)->default('');
            $table->string('cliente_nome', 60)->default('');
            $table->char('c5_emissao', 8)->default('');
            $table->string('etapa', 30)->nullable();           // carteira | expedicao | separacao | conferencia | logistica | aguardando_faturamento | faturado; null = encerrado/sumido
            $table->dateTime('etapa_desde')->nullable();       // UTC
            $table->boolean('parcial')->default(false);        // em aberto com parte já faturada
            $table->float('valor_total')->default(0);          // soma de C6_VALOR
            $table->float('valor_saldo')->default(0);          // a faturar
            $table->float('valor_faturado')->default(0);       // faturado
            $table->char('entrega_prevista', 8)->default('');  // menor C6_ENTREG dos itens com saldo
            $table->char('faturado_em', 8)->default('');       // maior C6_DATFAT
            $table->dateTime('calculado_em')->nullable();
            $table->unique(['c5_filial', 'c5_num']);
            $table->index('c5_num');
            $table->index(['etapa', 'entrega_prevista']);
            $table->index('faturado_em');
        });

        // A procedure passa a copiar também a SC9010 (só no SQL Server).
        ProceduresBanco::instalar('sp_sincronizar_pedidos_venda');
    }

    public function down(): void
    {
        Schema::dropIfExists('pedidos_venda_etapas');
        Schema::dropIfExists('regras_etapa_pedido');
        Schema::dropIfExists('pedidos_venda_liberacoes');
    }
};
