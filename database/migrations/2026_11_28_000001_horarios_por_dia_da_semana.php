<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Horário de início e fim por dia da semana (máquinas e mantenedores): JSON {"1":["07:00","17:00"],…}.
 * Só acrescenta a coluna; quem não a tem segue dias_semana + jornada_inicio/jornada_fim.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['maquinas', 'os_mantenedores'] as $tabela) {
            if (! Schema::hasColumn($tabela, 'horarios_semana')) {
                Schema::table($tabela, function (Blueprint $t) {
                    $t->string('horarios_semana', 200)->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['maquinas', 'os_mantenedores'] as $tabela) {
            if (Schema::hasColumn($tabela, 'horarios_semana')) {
                Schema::table($tabela, fn (Blueprint $t) => $t->dropColumn('horarios_semana'));
            }
        }
    }
};
