<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Módulo "Ordens de serviço" (manutenção): Solicitação de Serviço (SS) → aprovação → Ordem de Serviço (OS),
 * apontamento de horas dos mantenedores, materiais (almoxarifado/compras), histórico, preventivas (PAMP) com checklist
 * e justificativas de horas incomuns por setor.
 * - Cadastros (áreas executantes, mantenedores, sugestões, planos) têm "unidade" (manutencao | tecnologia | ambos).
 *   SS e OS têm a unidade calculada da área executante/máquina (só manutencao | tecnologia).
 * - Datas em UTC. Chaves estrangeiras sem exclusão em cascata (cadastros são desativados, não excluídos).
 * - Únicos "quando preenchido": no SQL Server um UNIQUE comum aceita um único NULL, por isso lá o índice é filtrado
 *   (WHERE coluna IS NOT NULL); no SQLite (testes) NULLs não colidem e o UNIQUE comum já basta.
 * Só CRIA tabelas novas: nenhum registro existente é apagado ou alterado.
 */
return new class extends Migration
{
    private const CADASTROS = ['os_areas_executantes', 'os_mantenedores', 'os_sugestoes_descricao', 'os_planos_preventiva'];

    public function up(): void
    {
        $sqlsrv = DB::getDriverName() === 'sqlsrv';

        Schema::create('os_areas_executantes', function (Blueprint $table) {
            $table->id();
            $table->string('unidade', 20)->default('manutencao');
            $table->string('codigo', 20)->unique();
            $table->string('nome', 80);
            $table->boolean('ativo')->default(true);
            $this->auditoria($table);
            $table->index('unidade');
        });

        Schema::create('os_mantenedores', function (Blueprint $table) use ($sqlsrv) {
            $table->id();
            $table->string('unidade', 20)->default('manutencao');
            $table->foreignId('usuario_id')->nullable()->constrained('usuarios'); // login do mantenedor (se tiver)
            $table->string('nome', 120);
            $table->string('matricula', 20)->nullable();
            $table->foreignId('area_executante_id')->nullable()->constrained('os_areas_executantes');
            $table->string('centro_custo', 20)->nullable();          // código do CC (CTT_CUSTO), como maquinas.centro_custo
            $table->string('jornada_inicio', 5)->default('07:00');   // HH:MM no fuso local
            $table->string('jornada_fim', 5)->default('17:00');
            $table->smallInteger('intervalo_min')->default(60);
            $table->string('dias_semana', 7)->default('12345');      // ISO: 1 = segunda ... 7 = domingo
            $table->boolean('ativo')->default(true);
            $this->auditoria($table);
            $table->index('unidade');
            $table->index('area_executante_id');
            if (! $sqlsrv) {
                $table->unique('usuario_id');
            }
        });

        Schema::create('os_sugestoes_descricao', function (Blueprint $table) {
            $table->id();
            $table->string('unidade', 20)->default('manutencao');
            $table->string('texto', 200);
            $table->boolean('ativo')->default(true);
            $this->auditoria($table);
            $table->index('unidade');
        });

        Schema::create('os_planos_preventiva', function (Blueprint $table) {
            $table->id();
            $table->string('unidade', 20)->default('manutencao');
            $table->string('codigo', 20)->unique();
            $table->string('descricao', 200);
            $table->foreignId('maquina_id')->nullable()->constrained('maquinas');
            $table->foreignId('area_executante_id')->constrained('os_areas_executantes');
            $table->foreignId('responsavel_id')->nullable()->constrained('os_mantenedores');
            $table->string('prioridade', 10)->default('media');
            $table->string('periodicidade', 10);                     // semanal | quinzenal | meses
            $table->string('meses', 12)->default('111111111111');    // jan..dez: '1' = tem preventiva no mês
            $table->smallInteger('dia_mes')->default(1);
            $table->date('proxima_em')->nullable();
            $table->smallInteger('antecedencia_dias')->default(7);
            $table->integer('duracao_prevista_min')->nullable();
            $table->boolean('ativo')->default(true);
            $this->auditoria($table);
            $table->index('unidade');
            $table->index(['ativo', 'proxima_em']);
            $table->index('maquina_id');
        });

        Schema::create('os_planos_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plano_preventiva_id')->constrained('os_planos_preventiva');
            $table->smallInteger('ordem')->default(0);
            $table->string('descricao', 300);
            $table->boolean('ativo')->default(true);
            $this->auditoria($table);
            $table->index(['plano_preventiva_id', 'ordem']);
        });

        Schema::create('solicitacoes_servico', function (Blueprint $table) {
            $table->id();                                            // nº da SS
            $table->string('unidade', 20);
            $table->foreignId('solicitante_id')->constrained('usuarios');
            $table->string('tipo', 15);                              // corretiva | preventiva | servico_geral
            $table->foreignId('maquina_id')->nullable()->constrained('maquinas');
            $table->foreignId('area_executante_id')->constrained('os_areas_executantes');
            $table->string('centro_custo', 20)->nullable();
            $table->string('centro_custo_solicitante', 120)->nullable();
            $table->string('prioridade', 10)->default('media');      // baixa | media | alta | urgente
            $table->boolean('maquina_parada')->default(false);
            $table->string('descricao', 2000);
            $table->string('status', 12)->default('pendente');       // pendente | aprovada | rejeitada | cancelada
            $table->foreignId('analisado_por_id')->nullable()->constrained('usuarios');
            $table->dateTime('analisado_em')->nullable();
            $table->string('motivo_rejeicao', 500)->nullable();
            $table->foreignId('apontamento_estado_id')->nullable()->constrained('apontamentos_estado'); // parada que originou
            $table->foreignId('ordem_producao_id')->nullable()->constrained('ordens_producao');
            $this->auditoria($table);
            $table->index(['unidade', 'status', 'id']);
            $table->index(['solicitante_id', 'id']);
            $table->index('maquina_id');
        });

        Schema::create('ordens_servico', function (Blueprint $table) use ($sqlsrv) {
            $table->id();                                            // nº da OS
            $table->string('unidade', 20);
            $table->foreignId('solicitacao_id')->nullable()->constrained('solicitacoes_servico');
            $table->foreignId('plano_preventiva_id')->nullable()->constrained('os_planos_preventiva');
            $table->date('prevista_para')->nullable();
            $table->string('tipo', 15);
            $table->foreignId('maquina_id')->nullable()->constrained('maquinas');
            $table->foreignId('area_executante_id')->constrained('os_areas_executantes');
            $table->string('centro_custo', 20)->nullable();
            $table->string('centro_custo_solicitante', 120)->nullable();
            $table->string('prioridade', 10);
            $table->boolean('maquina_parada')->default(false);
            $table->string('descricao', 2000);
            $table->foreignId('solicitante_id')->nullable()->constrained('usuarios');
            $table->foreignId('aprovado_por_id')->nullable()->constrained('usuarios');
            $table->foreignId('responsavel_id')->nullable()->constrained('os_mantenedores');
            $table->string('status', 20)->default('aberta');         // aberta | em_execucao | aguardando_material | concluida | encerrada | cancelada
            $table->dateTime('iniciada_em')->nullable();
            $table->dateTime('concluida_em')->nullable();
            $table->string('solucao', 2000)->nullable();
            $table->foreignId('aceite_por_id')->nullable()->constrained('usuarios');
            $table->dateTime('aceite_em')->nullable();
            $table->string('aceite_observacao', 500)->nullable();
            $table->string('cancelamento_motivo', 500)->nullable();
            $table->foreignId('ordem_producao_id')->nullable()->constrained('ordens_producao');
            $table->foreignId('apontamento_estado_id')->nullable()->constrained('apontamentos_estado');
            $table->integer('total_minutos')->default(0);            // soma dos apontamentos (desnormalizada)
            $this->auditoria($table);
            $table->index(['unidade', 'status', 'id']);
            $table->index(['responsavel_id', 'status']);
            $table->index(['maquina_id', 'criado_em']);
            $table->index(['status', 'prevista_para']);
            $table->index('solicitante_id');
            if (! $sqlsrv) {
                $table->unique('solicitacao_id');
                $table->unique(['plano_preventiva_id', 'prevista_para']);
            }
        });

        Schema::create('os_apontamentos_horas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ordem_servico_id')->constrained('ordens_servico');
            $table->foreignId('mantenedor_id')->constrained('os_mantenedores');
            $table->dateTime('inicio');
            $table->dateTime('fim')->nullable();                     // vazio = apontamento rodando
            $table->integer('minutos_normais')->nullable();
            $table->integer('minutos_extras')->nullable();
            $table->string('descricao', 1000)->nullable();
            $this->auditoria($table);                                // criado_por_id = "lançado por"
            $table->index(['ordem_servico_id', 'inicio']);
            $table->index(['mantenedor_id', 'inicio']);
            $table->index('inicio');
        });

        Schema::create('os_materiais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ordem_servico_id')->constrained('ordens_servico');
            $table->string('produto_codigo', 30)->nullable();        // B1_COD
            $table->string('descricao', 200);
            $table->decimal('quantidade', 14, 4);
            $table->string('um', 4)->nullable();
            $table->string('status', 12)->default('requisitado');    // requisitado | disponivel | em_compra | recebido | entregue | cancelado
            $table->string('solicitacao_compra', 30)->nullable();
            $table->decimal('custo_unitario', 18, 4)->nullable();
            $table->string('observacao', 500)->nullable();
            $this->auditoria($table);
            $table->index(['status', 'id']);
            $table->index('ordem_servico_id');
        });

        Schema::create('os_historico', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ordem_servico_id')->nullable()->constrained('ordens_servico');
            $table->foreignId('solicitacao_id')->nullable()->constrained('solicitacoes_servico');
            $table->string('acao', 30);
            $table->string('status_anterior', 20)->nullable();
            $table->string('status_novo', 20)->nullable();
            $table->string('observacao', 1000)->nullable();
            $table->foreignId('usuario_id')->nullable()->constrained('usuarios');
            $table->dateTime('criado_em')->nullable();
            $table->index(['ordem_servico_id', 'id']);
            $table->index(['solicitacao_id', 'id']);
        });

        Schema::create('os_checklist', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ordem_servico_id')->constrained('ordens_servico');
            $table->foreignId('plano_item_id')->nullable()->constrained('os_planos_itens');
            $table->smallInteger('ordem')->default(0);
            $table->string('descricao', 300);
            $table->string('resposta', 3)->nullable();               // ok | nok | na
            $table->string('observacao', 300)->nullable();
            $table->foreignId('respondido_por_id')->nullable()->constrained('usuarios');
            $table->dateTime('respondido_em')->nullable();
            $table->index(['ordem_servico_id', 'ordem']);
        });

        Schema::create('os_justificativas_setor', function (Blueprint $table) {
            $table->id();
            $table->string('unidade', 20);
            $table->string('centro_custo', 120);                     // setor
            $table->date('competencia');                             // 1º dia do mês
            $table->string('texto', 1000);
            $this->auditoria($table);
            $table->unique(['unidade', 'centro_custo', 'competencia']);
        });

        if ($sqlsrv) {
            $this->regrasSqlServer();
        }
    }

    /** Índices filtrados e regras CHECK (só no SQL Server). */
    private function regrasSqlServer(): void
    {
        $sql = [
            // Únicos "quando preenchido" e um apontamento aberto por mantenedor.
            'CREATE UNIQUE INDEX ux_os_mantenedores_usuario ON os_mantenedores (usuario_id) WHERE usuario_id IS NOT NULL',
            'CREATE UNIQUE INDEX ux_ordens_servico_solicitacao ON ordens_servico (solicitacao_id) WHERE solicitacao_id IS NOT NULL',
            'CREATE UNIQUE INDEX ux_ordens_servico_plano_prevista ON ordens_servico (plano_preventiva_id, prevista_para) WHERE plano_preventiva_id IS NOT NULL',
            'CREATE UNIQUE INDEX ux_os_apontamentos_horas_aberto ON os_apontamentos_horas (mantenedor_id) WHERE fim IS NULL',
        ];

        foreach (self::CADASTROS as $tabela) {
            $sql[] = "ALTER TABLE {$tabela} ADD CONSTRAINT ck_{$tabela}_unidade CHECK (unidade IN ('manutencao', 'tecnologia', 'ambos'))";
        }
        $tipos = "'corretiva', 'preventiva', 'servico_geral'";
        $prioridades = "'baixa', 'media', 'alta', 'urgente'";
        $sql = array_merge($sql, [
            "ALTER TABLE os_mantenedores ADD CONSTRAINT ck_os_mantenedores_jornada CHECK (jornada_inicio LIKE '[0-2][0-9]:[0-5][0-9]' AND jornada_fim LIKE '[0-2][0-9]:[0-5][0-9]')",
            "ALTER TABLE os_mantenedores ADD CONSTRAINT ck_os_mantenedores_dias_semana CHECK (dias_semana NOT LIKE '%[^1-7]%')",
            'ALTER TABLE os_mantenedores ADD CONSTRAINT ck_os_mantenedores_intervalo CHECK (intervalo_min >= 0)',

            "ALTER TABLE os_planos_preventiva ADD CONSTRAINT ck_os_planos_preventiva_prioridade CHECK (prioridade IN ({$prioridades}))",
            "ALTER TABLE os_planos_preventiva ADD CONSTRAINT ck_os_planos_preventiva_periodicidade CHECK (periodicidade IN ('semanal', 'quinzenal', 'meses'))",
            "ALTER TABLE os_planos_preventiva ADD CONSTRAINT ck_os_planos_preventiva_meses CHECK (LEN(meses) = 12 AND meses NOT LIKE '%[^01]%')",
            'ALTER TABLE os_planos_preventiva ADD CONSTRAINT ck_os_planos_preventiva_dia_mes CHECK (dia_mes BETWEEN 1 AND 31)',
            'ALTER TABLE os_planos_preventiva ADD CONSTRAINT ck_os_planos_preventiva_antecedencia CHECK (antecedencia_dias >= 0)',
            'ALTER TABLE os_planos_preventiva ADD CONSTRAINT ck_os_planos_preventiva_duracao CHECK (duracao_prevista_min IS NULL OR duracao_prevista_min > 0)',

            "ALTER TABLE solicitacoes_servico ADD CONSTRAINT ck_solicitacoes_servico_unidade CHECK (unidade IN ('manutencao', 'tecnologia'))",
            "ALTER TABLE solicitacoes_servico ADD CONSTRAINT ck_solicitacoes_servico_tipo CHECK (tipo IN ({$tipos}))",
            "ALTER TABLE solicitacoes_servico ADD CONSTRAINT ck_solicitacoes_servico_prioridade CHECK (prioridade IN ({$prioridades}))",
            "ALTER TABLE solicitacoes_servico ADD CONSTRAINT ck_solicitacoes_servico_status CHECK (status IN ('pendente', 'aprovada', 'rejeitada', 'cancelada'))",

            "ALTER TABLE ordens_servico ADD CONSTRAINT ck_ordens_servico_unidade CHECK (unidade IN ('manutencao', 'tecnologia'))",
            "ALTER TABLE ordens_servico ADD CONSTRAINT ck_ordens_servico_tipo CHECK (tipo IN ({$tipos}))",
            "ALTER TABLE ordens_servico ADD CONSTRAINT ck_ordens_servico_prioridade CHECK (prioridade IN ({$prioridades}))",
            "ALTER TABLE ordens_servico ADD CONSTRAINT ck_ordens_servico_status CHECK (status IN ('aberta', 'em_execucao', 'aguardando_material', 'concluida', 'encerrada', 'cancelada'))",
            'ALTER TABLE ordens_servico ADD CONSTRAINT ck_ordens_servico_total_minutos CHECK (total_minutos >= 0)',

            'ALTER TABLE os_apontamentos_horas ADD CONSTRAINT ck_os_apontamentos_horas_periodo CHECK (fim IS NULL OR fim >= inicio)',
            'ALTER TABLE os_apontamentos_horas ADD CONSTRAINT ck_os_apontamentos_horas_minutos CHECK ((minutos_normais IS NULL OR minutos_normais >= 0) AND (minutos_extras IS NULL OR minutos_extras >= 0))',

            "ALTER TABLE os_materiais ADD CONSTRAINT ck_os_materiais_status CHECK (status IN ('requisitado', 'disponivel', 'em_compra', 'recebido', 'entregue', 'cancelado'))",
            'ALTER TABLE os_materiais ADD CONSTRAINT ck_os_materiais_quantidade CHECK (quantidade > 0)',
            'ALTER TABLE os_materiais ADD CONSTRAINT ck_os_materiais_custo CHECK (custo_unitario IS NULL OR custo_unitario >= 0)',

            'ALTER TABLE os_historico ADD CONSTRAINT ck_os_historico_origem CHECK (ordem_servico_id IS NOT NULL OR solicitacao_id IS NOT NULL)',

            "ALTER TABLE os_checklist ADD CONSTRAINT ck_os_checklist_resposta CHECK (resposta IS NULL OR resposta IN ('ok', 'nok', 'na'))",

            "ALTER TABLE os_justificativas_setor ADD CONSTRAINT ck_os_justificativas_setor_unidade CHECK (unidade IN ('manutencao', 'tecnologia', 'ambos'))",
            'ALTER TABLE os_justificativas_setor ADD CONSTRAINT ck_os_justificativas_setor_competencia CHECK (DAY(competencia) = 1)',
        ]);

        foreach ($sql as $comando) {
            DB::statement($comando);
        }
    }

    private function auditoria(Blueprint $table): void
    {
        $table->dateTime('criado_em')->nullable();
        $table->foreignId('criado_por_id')->nullable()->constrained('usuarios');
        $table->dateTime('atualizado_em')->nullable();
        $table->foreignId('atualizado_por_id')->nullable()->constrained('usuarios');
    }

    public function down(): void
    {
        // As regras CHECK e os índices filtrados saem junto com as tabelas.
        foreach ([
            'os_justificativas_setor', 'os_checklist', 'os_historico', 'os_materiais', 'os_apontamentos_horas',
            'ordens_servico', 'solicitacoes_servico', 'os_planos_itens', 'os_planos_preventiva',
            'os_sugestoes_descricao', 'os_mantenedores', 'os_areas_executantes',
        ] as $tabela) {
            Schema::dropIfExists($tabela);
        }
    }
};
