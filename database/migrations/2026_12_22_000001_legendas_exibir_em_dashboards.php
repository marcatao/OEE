<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Legenda "Exibir em dashboards" (pedido do usuário em 24/11/2026): com "Não", o tempo dela não aparece nem entra nos
 * cálculos dos dashboards de máquinas (Dashboard por máquina: tempo por legenda, disponibilidade, performance, maior
 * parada); a Timeline continua mostrando tudo. Só ACRESCENTA a coluna: o padrão 1 vale para as legendas que já existem
 * (todas nascem "Sim"); nenhum registro é alterado.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('legendas', 'exibir_dashboards')) {
            Schema::table('legendas', fn (Blueprint $t) => $t->boolean('exibir_dashboards')->default(true));
        }
    }

    public function down(): void
    {
        Schema::table('legendas', fn (Blueprint $t) => $t->dropColumn('exibir_dashboards'));
    }
};
