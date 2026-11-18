<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Ordens de serviço:
 * - risco_integridade: "oferece risco à integridade" (de pessoas ou do equipamento), como "máquina parada", na SS e
 *   copiado para a OS;
 * - automatica + origem_iot: SS/OS aberta sozinha pelo apontamento de uma legenda que "abre SS" (origem_iot = IoT e
 *   canal que apontaram, ex.: "GW01 / chd1_value");
 * - legendas.abre_ss_unidade + abre_ss_area_executante_id: a legenda que abre SS e para qual unidade/área.
 * Só ACRESCENTA colunas: os registros existentes ficam como estão (sem risco, não automáticos, legendas sem SS).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['solicitacoes_servico', 'ordens_servico'] as $tabela) {
            Schema::table($tabela, function (Blueprint $table) {
                $table->boolean('risco_integridade')->default(false);
                $table->boolean('automatica')->default(false);
                $table->string('origem_iot', 120)->nullable();
            });
        }
        Schema::table('legendas', function (Blueprint $table) {
            $table->string('abre_ss_unidade', 20)->nullable();
            $table->foreignId('abre_ss_area_executante_id')->nullable()->constrained('os_areas_executantes');
        });
    }

    public function down(): void
    {
        Schema::table('legendas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('abre_ss_area_executante_id');
            $table->dropColumn('abre_ss_unidade');
        });
        foreach (['solicitacoes_servico', 'ordens_servico'] as $tabela) {
            Schema::table($tabela, fn (Blueprint $t) => $t->dropColumn(['risco_integridade', 'automatica', 'origem_iot']));
        }
    }
};
