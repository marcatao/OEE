<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Dois ambientes no portal: MANUTENÇÃO (produção) e TECNOLOGIA.
 * - usuario_perfis: cada usuário pode ter vários perfis (operador, administrador de produção, administrador de
 *   tecnologia, mantenedor de produção, mantenedor de tecnologia). A coluna antiga usuarios.perfil continua como está
 *   (usuário ainda sem linhas aqui segue o perfil antigo: admin = tudo, operador = operador).
 * - unidade nos cadastros (máquinas, legendas, dispositivos IoT, centros de custo, plantas e linhas):
 *   manutencao | tecnologia | ambos. Os cadastros que já existem são todos de MANUTENÇÃO (valor padrão da coluna nova).
 * Só ACRESCENTA tabela e colunas: nenhum registro existente é apagado ou alterado.
 */
return new class extends Migration
{
    private const CADASTROS = ['maquinas', 'legendas', 'dispositivos_iot', 'centros_custo', 'plantas', 'linhas'];

    public function up(): void
    {
        Schema::create('usuario_perfis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios');
            $table->string('perfil', 30); // operador | admin_producao | admin_tecnologia | mantenedor_producao | mantenedor_tecnologia
            $table->dateTime('criado_em')->nullable();
            $table->foreignId('criado_por_id')->nullable()->constrained('usuarios');
            $table->unique(['usuario_id', 'perfil']);
        });

        foreach (self::CADASTROS as $tabela) {
            Schema::table($tabela, function (Blueprint $table) {
                $table->string('unidade', 20)->default('manutencao'); // manutencao | tecnologia | ambos
                $table->index('unidade');
            });
        }

        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement("ALTER TABLE usuario_perfis ADD CONSTRAINT ck_usuario_perfis_perfil CHECK (perfil IN ('operador', 'admin_producao', 'admin_tecnologia', 'mantenedor_producao', 'mantenedor_tecnologia'))");
            foreach (self::CADASTROS as $tabela) {
                DB::statement("ALTER TABLE {$tabela} ADD CONSTRAINT ck_{$tabela}_unidade CHECK (unidade IN ('manutencao', 'tecnologia', 'ambos'))");
            }
        }
    }

    public function down(): void
    {
        foreach (self::CADASTROS as $tabela) {
            if (DB::getDriverName() === 'sqlsrv') {
                DB::statement("ALTER TABLE {$tabela} DROP CONSTRAINT ck_{$tabela}_unidade");
            }
            Schema::table($tabela, function (Blueprint $table) use ($tabela) {
                $table->dropIndex("{$tabela}_unidade_index");
                $table->dropColumn('unidade');
            });
        }
        Schema::dropIfExists('usuario_perfis');
    }
};
