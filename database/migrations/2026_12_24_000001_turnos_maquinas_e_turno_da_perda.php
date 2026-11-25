<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Até 3 turnos por dia nas máquinas e o turno da perda (pedido do usuário em 25/11/2026). Só ACRESCENTA colunas vazias.
 * - maquinas.turnos_semana: JSON {"1":[["05:00","13:00"],["13:00","21:00"],["21:00","05:00"]],…} (turno 1, 2 e 3 de
 *   cada dia; fim antes do início = vira o dia). Vazio = vale a jornada antiga (horarios_semana = 1 turno). O portal
 *   continua gravando em horarios_semana o "envelope" do dia (início do 1º turno ao fim do último) para quem só lê ela.
 * - perdas_op.turno: 1, 2 ou 3 (portal: escolhido; app: o turno da máquina no horário do lançamento; null = fora de turno).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('maquinas', 'turnos_semana')) {
            Schema::table('maquinas', fn (Blueprint $t) => $t->string('turnos_semana', 1000)->nullable());
        }
        if (! Schema::hasColumn('perdas_op', 'turno')) {
            Schema::table('perdas_op', fn (Blueprint $t) => $t->unsignedTinyInteger('turno')->nullable());
        }
    }

    public function down(): void
    {
        Schema::table('maquinas', fn (Blueprint $t) => $t->dropColumn('turnos_semana'));
        Schema::table('perdas_op', fn (Blueprint $t) => $t->dropColumn('turno'));
    }
};
