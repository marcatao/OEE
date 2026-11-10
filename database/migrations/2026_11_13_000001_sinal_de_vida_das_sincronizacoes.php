<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Sinal de vida da sincronização: atualizado a cada lote. Uma execução "executando" sem sinal há mais de alguns
 * minutos é considerada parada (o processo morreu) e não impede a próxima. Só ACRESCENTA a coluna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sincronizacoes', function (Blueprint $table) {
            $table->dateTime('sinal_em')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sincronizacoes', function (Blueprint $table) {
            $table->dropColumn('sinal_em');
        });
    }
};
