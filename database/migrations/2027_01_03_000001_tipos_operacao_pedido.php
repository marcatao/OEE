<?php

use App\Support\ProceduresBanco;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Tipos de operação dos pedidos de venda que são sincronizados (Faturamento › Etapas dos pedidos, pedido do usuário):
 * a procedure dbo.sp_sincronizar_pedidos_venda só lê do Protheus os itens com C6_OPER (tipo de operação, tabela DJ da
 * SX5) num tipo ATIVO daqui — "1" e "01" são o mesmo tipo (código gravado sem zeros à esquerda). Nasce com os tipos
 * pedidos pelo usuário. Pedidos de venda ganham a coluna c6_oper. Reinstala a procedure.
 * Só ACRESCENTA: tabela nova (com os tipos iniciais) e uma coluna vazia; nenhum registro existente é alterado aqui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipos_operacao_pedido', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 3)->unique();      // C6_OPER sem zeros à esquerda ("1" = "01")
            $table->string('descricao', 80)->default('');
            $table->boolean('ativo')->default(true);    // excluído na tela = desativado (não apaga)
            $table->dateTime('criado_em')->nullable();
            $table->foreignId('criado_por_id')->nullable()->constrained('usuarios');
            $table->dateTime('atualizado_em')->nullable();
            $table->foreignId('atualizado_por_id')->nullable()->constrained('usuarios');
        });

        $agora = now();
        foreach ([
            '1' => 'VENDA DE MERCADORIA',
            '2' => 'SIMPLES REMESSA DE MATERIAL',
            '3' => 'VENDA PARA CONSUMIDOR FINAL',
            '5' => 'VENDA ENTREGA FUTURA',
            '7' => 'VENDA POR CONTA E ORDEM',
            '9' => 'VENDA ZFM',
            '10' => 'VENDA ALC',
            '11' => 'VENDA AMAZONIA OCIDENTAL',
        ] as $codigo => $descricao) {
            DB::table('tipos_operacao_pedido')->insert(['codigo' => (string) $codigo, 'descricao' => $descricao, 'ativo' => true,
                'criado_em' => $agora, 'atualizado_em' => $agora]);
        }

        Schema::table('pedidos_venda', function (Blueprint $table) {
            $table->string('c6_oper', 2)->default('');  // C6_OPER (tipo de operação do item)
        });

        ProceduresBanco::instalar('sp_sincronizar_pedidos_venda');
    }

    public function down(): void
    {
        Schema::table('pedidos_venda', fn (Blueprint $t) => $t->dropColumn('c6_oper'));
        Schema::dropIfExists('tipos_operacao_pedido');
    }
};
