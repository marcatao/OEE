<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mantenedores com até 3 turnos por dia, como as máquinas (pedido do usuário em 27/11/2026): JSON
 * {"1":{"1":["05:00","13:30"],"2":["13:30","22:00"]},…}. Só ACRESCENTA a coluna (vazia = o horário antigo do dia,
 * `horarios_semana`, vale como turno 1); nenhum registro é alterado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('os_mantenedores', function (Blueprint $t) {
            if (! Schema::hasColumn('os_mantenedores', 'turnos_semana')) {
                $t->text('turnos_semana')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('os_mantenedores', function (Blueprint $t) {
            $t->dropColumn('turnos_semana');
        });
    }
};
