<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Legenda que exige ordem de produção: no apontamento real com essa legenda, a OP é obrigatória.
 * Só ACRESCENTA a coluna (as legendas existentes ficam com "Não").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legendas', function (Blueprint $table) {
            $table->boolean('exige_op')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('legendas', fn (Blueprint $t) => $t->dropColumn('exige_op'));
    }
};
