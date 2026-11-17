<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Solicitação de compra (SC1, MATA110) pelo mesmo caminho do pedido de compra (tabelas muro ZP1/ZP2):
 * - pedidos_compra.tipo: pedido (pedido de compra, MATA120) | solicitacao (solicitação de compra, MATA110);
 * - pedidos_compra_itens.pedido_gerado: na solicitação, o nº do pedido de compra em que o Protheus a atendeu.
 * Só ACRESCENTA colunas: os registros já gravados são pedidos de compra (o padrão da coluna nova).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos_compra', function (Blueprint $table) {
            $table->string('tipo', 12)->default('pedido');
        });
        Schema::table('pedidos_compra_itens', function (Blueprint $table) {
            $table->string('pedido_gerado', 10)->nullable();
        });
        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement("ALTER TABLE pedidos_compra ADD CONSTRAINT ck_pedidos_compra_tipo CHECK (tipo IN ('pedido', 'solicitacao'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement('ALTER TABLE pedidos_compra DROP CONSTRAINT ck_pedidos_compra_tipo');
        }
        Schema::table('pedidos_compra_itens', fn (Blueprint $t) => $t->dropColumn('pedido_gerado'));
        Schema::table('pedidos_compra', fn (Blueprint $t) => $t->dropColumn('tipo'));
    }
};
