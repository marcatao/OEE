<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Ordens de serviço:
 * - "titulo" (título resumido) na SS e na OS. Coluna nova e vazia: as SS/OS antigas continuam sem título (as telas mostram
 *   o começo da descrição).
 * - Perfis novos "solicitante_manutencao" e "solicitante_tecnologia" (quem pode abrir SS para cada área): no SQL Server a
 *   regra CHECK de usuario_perfis.perfil é recriada aceitando também esses dois. Só AMPLIA a regra: nenhum registro é
 *   apagado ou alterado.
 */
return new class extends Migration
{
    private const PERFIS_ANTIGOS = ['operador', 'admin_producao', 'admin_tecnologia', 'mantenedor_producao', 'mantenedor_tecnologia'];

    private const PERFIS_NOVOS = ['solicitante_manutencao', 'solicitante_tecnologia'];

    public function up(): void
    {
        foreach (['solicitacoes_servico', 'ordens_servico'] as $tabela) {
            Schema::table($tabela, function (Blueprint $table) {
                $table->string('titulo', 120)->nullable();
            });
        }

        if (DB::getDriverName() === 'sqlsrv') {
            $this->regraPerfis([...self::PERFIS_ANTIGOS, ...self::PERFIS_NOVOS]);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlsrv') {
            $this->regraPerfis(self::PERFIS_ANTIGOS);
        }
        foreach (['solicitacoes_servico', 'ordens_servico'] as $tabela) {
            Schema::table($tabela, fn (Blueprint $table) => $table->dropColumn('titulo'));
        }
    }

    private function regraPerfis(array $perfis): void
    {
        $lista = implode(', ', array_map(fn ($p) => "'{$p}'", $perfis));
        DB::statement("IF OBJECT_ID('ck_usuario_perfis_perfil', 'C') IS NOT NULL ALTER TABLE usuario_perfis DROP CONSTRAINT ck_usuario_perfis_perfil");
        DB::statement("ALTER TABLE usuario_perfis ADD CONSTRAINT ck_usuario_perfis_perfil CHECK (perfil IN ({$lista}))");
    }
};
