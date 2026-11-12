<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Terceira área (unidade): FERRAMENTARIA, ao lado de Manutenção e Tecnologia, com os perfis Solicitante, Mantenedor e
 * Administrador de Ferramentaria. No SQL Server as regras CHECK de "unidade" e de usuario_perfis.perfil são recriadas
 * aceitando também os valores novos. Só AMPLIA as regras: nenhum registro é apagado ou alterado.
 */
return new class extends Migration
{
    /** Cadastros: manutencao | tecnologia | ferramentaria | ambos ("todas as áreas"). */
    private const COM_TODAS = ['maquinas', 'legendas', 'dispositivos_iot', 'centros_custo', 'plantas', 'linhas', 'os_areas_executantes',
        'os_mantenedores', 'os_sugestoes_descricao', 'os_planos_preventiva', 'os_justificativas_setor', 'os_ferramentas', 'ferramentas'];

    /** SS, OS e solicitação de material: sempre UMA área. */
    private const SO_UMA = ['solicitacoes_servico', 'ordens_servico', 'solicitacoes_material'];

    private const PERFIS = ['operador', 'admin_producao', 'admin_tecnologia', 'mantenedor_producao', 'mantenedor_tecnologia',
        'solicitante_manutencao', 'solicitante_tecnologia'];

    private const PERFIS_NOVOS = ['solicitante_ferramentaria', 'mantenedor_ferramentaria', 'admin_ferramentaria'];

    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            return;
        }
        foreach (self::COM_TODAS as $tabela) {
            $this->regra($tabela, "ck_{$tabela}_unidade", 'unidade', ['manutencao', 'tecnologia', 'ferramentaria', 'ambos']);
        }
        foreach (self::SO_UMA as $tabela) {
            $this->regra($tabela, "ck_{$tabela}_unidade", 'unidade', ['manutencao', 'tecnologia', 'ferramentaria']);
        }
        $this->regra('usuario_perfis', 'ck_usuario_perfis_perfil', 'perfil', [...self::PERFIS, ...self::PERFIS_NOVOS]);
    }

    public function down(): void
    {
        // Sem volta automática: registros novos já podem ter "ferramentaria" ou os perfis novos, que as regras antigas recusariam.
    }

    private function regra(string $tabela, string $nome, string $coluna, array $valores): void
    {
        $existe = DB::selectOne('SELECT OBJECT_ID(?) AS id', [$tabela]);
        if ($existe !== null && $existe->id === null) {
            return; // tabela ainda não existe neste banco (em simulação, como no banco:documentar, a consulta não volta nada)
        }
        $lista = implode(', ', array_map(fn ($v) => "'{$v}'", $valores));
        DB::statement("IF OBJECT_ID('{$nome}', 'C') IS NOT NULL ALTER TABLE {$tabela} DROP CONSTRAINT {$nome}");
        DB::statement("ALTER TABLE {$tabela} ADD CONSTRAINT {$nome} CHECK ({$coluna} IN ({$lista}))");
    }
};
