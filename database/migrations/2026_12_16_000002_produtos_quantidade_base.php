<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quantidade base da estrutura do produto (SB1.B1_QB): a SG1 informa G1_QUANT para B1_QB unidades do pai (ex.: 1000).
 * Só acrescenta a coluna; é preenchida pela sincronização dos produtos.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('produtos_protheus', 'b1_qb')) {
            Schema::table('produtos_protheus', function (Blueprint $table) {
                $table->float('b1_qb')->nullable(); // B1_QB (quantidade base da estrutura; vazio/0 = 1)
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('produtos_protheus', 'b1_qb')) {
            Schema::table('produtos_protheus', fn (Blueprint $t) => $t->dropColumn('b1_qb'));
        }
    }
};
