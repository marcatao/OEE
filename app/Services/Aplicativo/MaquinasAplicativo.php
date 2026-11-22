<?php

namespace App\Services\Aplicativo;

use App\Models\ApontamentoEstado;
use App\Models\Ferramenta;
use App\Models\Legenda;
use App\Models\Maquina;
use App\Models\OrdemProducao;
use App\Services\Calendario\DiasUteis;
use App\Services\CustoOrdemProducao;
use App\Services\EngenhariaOrdemProducao;
use App\Services\Filtros\FiltroTimeline;
use App\Services\HorarioApontamento;
use App\Services\Manutencao\ChamadoDaParada;
use App\Services\MotivoParada;
use App\Services\PecasProduzidas;
use App\Services\SituacaoMaquina;
use App\Services\StatusOrdemProducao;
use App\Services\TrocaOpMaquina;
use App\Support\JornadaSemanal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Função "Máquinas" do aplicativo (operador): máquinas ativas com IoT ativo, apontamentos reais da máquina, motivo
 * da parada "sem motivo" (legenda config('oee.app.legenda_sem_motivo'), gravada pelo IoT), OP da máquina (com
 * roteiro e molde) e cavidades ativas do molde. Usa as mesmas regras das telas do portal (SituacaoMaquina,
 * StatusOrdemProducao, HorarioApontamento) e o escopo de unidade do usuário logado (TemUnidade).
 */
class MaquinasAplicativo
{
    public function __construct(
        private SituacaoMaquina $situacao,
        private StatusOrdemProducao $status,
        private EngenhariaOrdemProducao $engenharia,
        private CustoOrdemProducao $custo,
        private DiasUteis $diasUteis,
        private PecasProduzidas $pecas,
    ) {}

    /** Máquinas ativas com IoT ativo que o usuário enxerga. */
    public function consulta(): Builder
    {
        return Maquina::query()->where('ativo', true)->comIotAtivo();
    }

    public function lista(): array
    {
        $maquinas = $this->consulta()->with(['ordemProducao', 'situacaoLegenda', 'linha.planta'])->orderBy('nome')->get();
        $pendentes = $this->pendentesSemMotivo()->whereIn('maquina_id', $maquinas->pluck('id'))
            ->selectRaw('maquina_id, count(*) as total')->groupBy('maquina_id')->pluck('total', 'maquina_id');
        $produtos = $this->custo->produtos($maquinas->pluck('ordemProducao')->filter()->pluck('c2_produto'));
        $chamados = ChamadoDaParada::dasMaquinas($maquinas);

        return $maquinas->map(fn (Maquina $m) => [
            'id' => $m->id,
            'codigo' => rtrim((string) $m->codigo),
            'nome' => $m->nome,
            'local' => $m->localizacao(),
            'situacao' => $m->situacao,
            'legenda' => $this->legenda($m->situacaoLegenda),
            'op' => $m->ordemProducao ? $this->resumoOp($m->ordemProducao, $produtos) : null,
            'sem_motivo' => (int) ($pendentes[$m->id] ?? 0),
            // Parada com SS/OS aberta pela legenda: número e situação, para o operador acompanhar.
            'chamado' => $chamados[(int) $m->id] ?? null,
        ])->values()->all();
    }

    /** Tudo da tela da máquina: situação, apontamentos recentes, pendências, OP (roteiro e molde) e legendas de motivo. */
    public function detalhe(Maquina $maquina): array
    {
        $this->situacao->desvincularOpsFechadas(array_filter([$maquina->ordem_producao_id]));
        $maquina->refresh()->load(['ordemProducao', 'situacaoLegenda', 'linha.planta']);
        $fuso = HorarioApontamento::fuso();
        // Sempre só HOJE e só dentro do TURNO da máquina (jornada do dia no cadastro). Sem jornada para hoje: o
        // período padrão da Timeline do portal (01:00 até 23:59).
        [$deLocal, $ateLocal, $rotulo] = $this->periodoDeHoje($maquina);
        $de = $deLocal->utc();
        $ate = $ateLocal->utc();

        $apontamentos = ApontamentoEstado::with(['legenda', 'ordemProducao'])->where('maquina_id', $maquina->id)
            ->tocando($de, $ate)
            ->orderByDesc('inicio')->orderByDesc('id')->limit(300)->get();
        $pendentes = $this->pendentesSemMotivo()->with(['legenda', 'ordemProducao'])->where('maquina_id', $maquina->id)
            ->orderBy('inicio')->get();

        $op = $maquina->ordemProducao;
        $produtos = $op ? $this->custo->produtos([$op->c2_produto]) : collect();

        return [
            'servidor_agora' => now()->toIso8601String(),
            'segundos_atualizacao' => (int) config('oee.app.segundos_atualizacao', 10),
            'periodo' => [
                'de_utc' => $de->toIso8601String(),
                'ate_utc' => $ate->toIso8601String(),
                'rotulo' => $rotulo,
            ],
            'maquina' => [
                'id' => $maquina->id,
                'codigo' => rtrim((string) $maquina->codigo),
                'nome' => $maquina->nome,
                'local' => $maquina->localizacao(),
                'situacao' => $maquina->situacao,
                'situacao_desde' => $this->hora($maquina->situacao_desde, $fuso, 'd/m H:i'),
                'legenda' => $this->legenda($maquina->situacaoLegenda),
                'chamado' => ChamadoDaParada::da($maquina),
            ],
            'apontamentos' => $apontamentos->map(fn ($a) => $this->apontamento($a, $fuso))->values()->all(),
            'sem_motivo' => $pendentes->map(fn ($a) => $this->apontamento($a, $fuso))->values()->all(),
            'op' => $dadosOp = ($op ? $this->op($op, $maquina, $produtos) : null),
            'legendas_motivo' => $this->legendasMotivo()->map(fn ($l) => $this->legenda($l))->values()->all(),
            'producao' => $this->producao($maquina, $dadosOp, $apontamentos, $de, $ate, $fuso),
        ];
    }

    /**
     * Período de hoje mostrado na tela: o turno da máquina (horário de início e fim do dia da semana de hoje, em
     * JornadaSemanal; fim antes do início = termina amanhã). Sem jornada hoje (ou fora do calendário), o padrão da
     * Timeline.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string} início, fim (exclusivo) e rótulo
     */
    public function periodoDeHoje(Maquina $maquina): array
    {
        $hoje = CarbonImmutable::now(HorarioApontamento::fuso());
        $jornadas = JornadaSemanal::de($maquina);
        // Turno noturno de ontem ainda em andamento (ex.: seg 22:00–06:00, agora ter 02:00): é ele que vale.
        $ontem = $hoje->subDay();
        $jornadaOntem = $jornadas[$ontem->dayOfWeekIso] ?? null;
        if ($jornadaOntem && $jornadaOntem[1] < $jornadaOntem[0] && $this->diasUteis->trabalha($maquina, $ontem->format('Y-m-d'))) {
            [$de, $ate] = FiltroTimeline::periodoDoDia($ontem, $jornadaOntem[0], $jornadaOntem[1]);
            if ($hoje->lessThan($ate->addMinute())) {
                return [$de, $ate->addMinute(), 'Turno de '.$ontem->format('d/m').' · '.$jornadaOntem[0].' às '.$jornadaOntem[1]];
            }
        }
        $jornada = $jornadas[$hoje->dayOfWeekIso] ?? null;
        if ($jornada && $this->diasUteis->trabalha($maquina, $hoje->format('Y-m-d'))) {
            [$de, $ate] = FiltroTimeline::periodoDoDia($hoje, $jornada[0], $jornada[1]);

            return [$de, $ate->addMinute(), 'Turno de hoje, '.$hoje->format('d/m').' · '.$jornada[0].' às '.$jornada[1]];
        }
        $padrao = FiltroTimeline::padrao();

        return [$padrao->de, $padrao->fimExclusivo(), 'Hoje, '.$hoje->format('d/m').' · '.$padrao->de->format('H:i')
            .' às '.$padrao->ate->format('H:i').($jornada ? ' (fora do calendário de trabalho)' : '')];
    }

    /** Troca a legenda "sem motivo" de UM apontamento (regra única em App\Services\MotivoParada, a mesma da Timeline). */
    public function informarMotivo(ApontamentoEstado $apontamento, int $legendaId): void
    {
        $this->conferirMaquina($apontamento->maquina_id);
        app(MotivoParada::class)->informar($apontamento, $legendaId);
    }

    /** Troca a OP da máquina: regra única em App\Services\TrocaOpMaquina (a mesma da Timeline do portal). */
    public function trocarOp(Maquina $maquina, string $numero, string $modo = TrocaOpMaquina::AUTOMATICO): OrdemProducao
    {
        return app(TrocaOpMaquina::class)->trocar($maquina, $numero, $modo, 'aplicativo');
    }

    /** @return list<int> ids dos moldes (SH4) da OP atual da máquina, como na tela do app. */
    public function moldesDaOpAtual(Maquina $maquina): array
    {
        $op = $maquina->ordemProducao;

        return $op ? array_map(fn ($m) => (int) $m['id'], $this->op($op, $maquina, collect())['moldes']) : [];
    }

    /** Cavidades ativas do molde: de 0 até a quantidade de cavidades do Protheus (H4_QTDCAV). */
    public function alterarCavidades(Ferramenta $ferramenta, int $cavidades): void
    {
        abort_if($ferramenta->d_e_l_e_t_ === '*', 404);
        if ($cavidades < 0 || $cavidades > (int) $ferramenta->h4_qtdcav) {
            throw ValidationException::withMessages(['cavidades' => 'As cavidades ativas vão de 0 a '.(int) $ferramenta->h4_qtdcav.'.']);
        }
        $ferramenta->update(['h4_qtdcavativas' => $cavidades]);
    }

    /** OPs abertas para escolher (busca pelo número da OP, SKU ou descrição do produto). */
    public function buscarOps(string $busca): array
    {
        $busca = trim($busca);
        if ($busca !== '' && mb_strlen($busca) < 2) {
            return []; // como no portal: a busca começa com 2 letras (sem nada, as 30 mais recentes)
        }
        $ops = OrdemProducao::apontaveis()
            ->busca($busca) // número da OP, SKU ou descrição do produto (igual ao portal)
            ->orderByDesc('numero')->limit(30)->get();
        $produtos = $this->custo->produtos($ops->pluck('c2_produto'));

        return $ops->map(fn ($op) => $this->resumoOp($op, $produtos))->values()->all();
    }

    public function conferirMaquina(int $maquinaId): Maquina
    {
        return $this->consulta()->findOrFail($maquinaId);
    }

    private function codigoSemMotivo(): string
    {
        return (string) config('oee.app.legenda_sem_motivo', '98');
    }

    /** Apontamentos com a legenda "sem motivo" nos últimos dias (pendências do operador). */
    private function pendentesSemMotivo(): Builder
    {
        return ApontamentoEstado::query()
            ->whereIn('legenda_id', Legenda::withoutGlobalScopes()->where('codigo', $this->codigoSemMotivo())->select('id'))
            ->where('inicio', '>=', now()->subDays((int) config('oee.app.dias_pendencia_sem_motivo', 7)));
    }

    private function legendasMotivo()
    {
        return app(MotivoParada::class)->legendas();
    }

    private function legenda(?Legenda $l): ?array
    {
        return $l ? ['id' => $l->id, 'codigo' => $l->codigo, 'descricao' => $l->descricao, 'tipo' => $l->tipo,
            'cor' => $l->cor, 'exige_op' => (bool) $l->exige_op] : null;
    }

    /**
     * Produção do turno para o gráfico do app: peças por hora (repartidas pelo tempo de cada apontamento de produção),
     * total do turno e peças por minuto agora (60 ÷ ciclo médio × cavidades). A meta (pç/min) vem em breve: null.
     */
    private function producao(Maquina $maquina, ?array $dadosOp, $apontamentos, CarbonImmutable $de, CarbonImmutable $ate, string $fuso): array
    {
        // Reaproveita os apontamentos já lidos (a tela é consultada a cada poucos segundos) e os moldes da OP.
        $porHora = $this->pecas->porHora($maquina, $de, $ate, $fuso, $apontamentos);
        $ciclo = $apontamentos->first()?->ciclo_medio;
        $cavidades = 1;
        foreach ($dadosOp['moldes'] ?? [] as $molde) {
            if ((int) $molde['cavidades_ativas'] > 0) {
                $cavidades = (int) $molde['cavidades_ativas'];
                break;
            }
        }

        return [
            'por_hora' => $porHora,
            'pecas_turno' => array_sum(array_column($porHora, 'pecas')),
            'ciclo_medio' => $ciclo === null ? null : (int) $ciclo,
            'cavidades' => $cavidades,
            'pecas_por_minuto' => $maquina->situacao === 'produzindo' ? PecasProduzidas::porMinuto($ciclo === null ? null : (int) $ciclo, $cavidades) : 0.0,
            'meta_por_minuto' => null,
        ];
    }

    private function apontamento(ApontamentoEstado $a, string $fuso): array
    {
        return [
            'id' => $a->id,
            'legenda' => $this->legenda($a->legenda),
            'sem_motivo' => $a->legenda?->codigo === $this->codigoSemMotivo(),
            'dia' => $this->hora($a->inicio, $fuso, 'd/m'),
            'inicio' => $this->hora($a->inicio, $fuso, 'H:i:s'),
            'fim' => $this->hora($a->fim, $fuso, 'H:i:s'),
            'inicio_utc' => $a->inicio?->toIso8601String(),
            'fim_utc' => $a->fim?->toIso8601String(),
            'minutos' => round($a->duracaoMin(), 1),
            'op' => $a->ordemProducao?->numero,
            'pecas' => $a->pecas,
        ];
    }

    private function resumoOp(OrdemProducao $op, $produtos): array
    {
        $produto = $produtos->get(rtrim((string) $op->c2_produto));

        return [
            'id' => $op->id,
            'numero' => $op->numero,
            'produto' => rtrim((string) $op->c2_produto),
            'descricao' => $produto?->descricao(),
            'status' => $op->status,
            'status_rotulo' => $op->rotuloStatus(),
            'quantidade' => (float) $op->c2_quant,
            'produzido' => (float) $op->c2_quje,
            'quantidade_real' => (float) $op->quantidade_real, // peças contadas pelo IoT
        ];
    }

    private function op(OrdemProducao $op, Maquina $maquina, $produtos): array
    {
        // Molde de qualquer unidade (quem vê a máquina vê o molde dela; mesmas cavidades das peças).
        $roteiro = $this->engenharia->roteiro($op, comMaquinas: false, todasUnidades: true);
        $codigoMaquina = rtrim((string) $maquina->codigo);
        $operacoes = $roteiro['operacoes']->map(fn ($o) => [
            'operacao' => trim($o->g2_operac),
            'descricao' => trim($o->g2_descri),
            'maquina' => rtrim($o->g2_ctrab),
            'ferramenta' => rtrim($o->g2_ferram),
            'desta_maquina' => rtrim($o->g2_ctrab) === $codigoMaquina,
        ])->values();
        // Molde: o da operação desta máquina; se nenhuma operação é dela, os do roteiro.
        $codigos = $operacoes->where('desta_maquina', true)->pluck('ferramenta')->filter()->unique();
        if ($codigos->isEmpty()) {
            $codigos = $operacoes->pluck('ferramenta')->filter()->unique();
        }
        $moldes = $codigos->map(fn ($c) => $roteiro['ferramentas']->get($c))->filter()->map(fn (Ferramenta $f) => [
            'id' => $f->id,
            'codigo' => $f->codigo(),
            'descricao' => trim($f->h4_descri),
            'cavidades' => (int) $f->h4_qtdcav,
            'cavidades_ativas' => (int) $f->h4_qtdcavativas,
        ])->values()->all();

        return $this->resumoOp($op, $produtos) + [
            'emissao' => OrdemProducao::data($op->c2_emissao ?? null),
            'roteiro' => ['codigo' => $roteiro['codigo'], 'operacoes' => $operacoes->all()],
            'moldes' => $moldes,
        ];
    }

    private function hora(?CarbonInterface $valor, string $fuso, string $formato): ?string
    {
        return $valor?->copy()->setTimezone($fuso)->format($formato);
    }
}
