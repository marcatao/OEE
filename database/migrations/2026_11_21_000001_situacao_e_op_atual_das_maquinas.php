<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Situação atual da máquina (produzindo | parada) e a OP atual, no cadastro da máquina. Atualizadas pelo cadastro e
 * pelo apontamento real mais recente (o tipo da legenda decide produzindo ou parada; apontamento sem OP desvincula a
 * OP). A Timeline lê daqui. Só ACRESCENTA colunas: as máquinas existentes ficam sem situação e sem OP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maquinas', function (Blueprint $table) {
            $table->string('situacao', 12)->nullable();                                   // produzindo | parada (vazio = sem informação)
            $table->dateTime('situacao_desde')->nullable();                               // desde quando (UTC)
            $table->foreignId('situacao_legenda_id')->nullable()->constrained('legendas'); // legenda que deu a situação (vazio = manual)
            $table->foreignId('ordem_producao_id')->nullable()->constrained('ordens_producao'); // OP atual
        });

        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement("ALTER TABLE maquinas ADD CONSTRAINT ck_maquinas_situacao CHECK (situacao IS NULL OR situacao IN ('produzindo', 'parada'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement('ALTER TABLE maquinas DROP CONSTRAINT ck_maquinas_situacao');
        }
        Schema::table('maquinas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('situacao_legenda_id');
            $table->dropConstrainedForeignId('ordem_producao_id');
            $table->dropColumn(['situacao', 'situacao_desde']);
        });
    }
};
