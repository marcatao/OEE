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
use App\Services\HorarioApontamento;
use App\Services\Manutencao\ChamadoDaParada;
use App\Services\MetaPorHora;
use App\Services\MetaProducao;
use App\Services\MotivoParada;
use App\Services\OpDoApontamento;
use App\Services\PanoramaMaquinas;
use App\Services\PecasProduzidas;
use App\Services\ProducaoPorHora;
use App\Services\SituacaoMaquina;
use App\Services\StatusOrdemProducao;
use App\Services\TrocaOpMaquina;
use App\Services\TurnoDaMaquina;
use App\Support\Formato;
use App\Support\JornadaSemanal;
use App\Support\Segundos;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
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
            'producao' => $this->producao($maquina, $op, $dadosOp, $apontamentos, $de, $ate, $fuso),
            'oee' => $this->oee($maquina, $de, $ate),
        ];
    }

    /**
     * OEE (IoT) do turno de hoje da máquina (o mesmo período do gráfico de produção): D × P × Q pelo panorama do
     * Dashboard por máquina (apontamentos reais, IoT e perdas apontadas). Guardado 30 s (o app consulta a cada 10 s).
     * Valores de 0 a 1; null = sem dado (ex.: OP sem meta = sem performance = sem OEE).
     *
     * @return array{oee: ?float, disponibilidade: ?float, performance: ?float, qualidade: ?float, pecas: int, perdas: float, texto: string}
     */
    private function oee(Maquina $maquina, CarbonImmutable $de, CarbonImmutable $ate): array
    {
        return Cache::remember("app.oee.{$maquina->id}.{$de->getTimestamp()}", 30, function () use ($maquina, $de, $ate) {
            $d = app(PanoramaMaquinas::class)->montar(new Collection([$maquina]), $de, $ate)['maquinas'][$maquina->id];
            $r = fn (?float $v) => $v === null ? null : round($v, 4);

            return [
                'oee' => $r($d['oeeIot']),
                'disponibilidade' => $r($d['disponibilidade']),
                'performance' => $r($d['performance']), // pode passar de 100% (no OEE entra limitada)
                'qualidade' => $r($d['qualidade']),
                'pecas' => (int) $d['pecasTodas'],
                'perdas' => (float) $d['perdas'],
                'texto' => $d['oeeIot'] === null ? '—' : Formato::pct($d['oeeIot'], 0),
                // nivel: bom ≥ 85%, medio ≥ 60%, ruim abaixo, sem = sem dado (as mesmas faixas do portal)
                'nivel' => str_replace('nivel-', '', Formato::nivel($d['oeeIot'])),
            ];
        });
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
        return app(TurnoDaMaquina::class)->periodo($maquina);
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

    /**
     * Detalhe de UM apontamento real (toque na lista da tela da máquina): horário, legenda, OP e produto, peças,
     * ciclos, cavidades, ciclo médio, observação e quem apontou/alterou. Só de máquina que o usuário enxerga.
     */
    public function detalheApontamento(int $id): array
    {
        $a = ApontamentoEstado::with(['legenda', 'ordemProducao', 'criadoPor', 'atualizadoPor'])->findOrFail($id);
        $maquina = $this->conferirMaquina((int) $a->maquina_id); // outra unidade ou sem IoT ativo: 404
        $fuso = HorarioApontamento::fuso();
        $op = $a->ordemProducao;
        $produto = $op ? $this->custo->produtos([$op->c2_produto])->get(rtrim((string) $op->c2_produto)) : null;
        $iot = $a->criado_por_id === null || str_starts_with((string) $a->observacao, '[IoT]');

        return $this->apontamento($a, $fuso) + [
            'maquina' => ['id' => (int) $maquina->id, 'nome' => $maquina->nome, 'codigo' => rtrim((string) $maquina->codigo)],
            'em_aberto' => $a->fim === null,
            'op_detalhe' => $op ? $this->resumoOp($op, collect([rtrim((string) $op->c2_produto) => $produto])->filter()) : null,
            'ciclos' => $a->ciclos === null ? null : (int) $a->ciclos,
            'cavidades' => $a->cavidades === null ? null : (int) $a->cavidades,
            'ciclo_medio_texto' => Segundos::ciclo(ApontamentoEstado::cicloEmSegundos($a->ciclo_medio_ms, $a->ciclo_medio)),
            'observacao' => trim((string) $a->observacao) !== '' ? trim((string) $a->observacao) : null,
            'referencia' => trim((string) $a->referencia) !== '' ? trim((string) $a->referencia) : null,
            'origem' => $iot ? 'IoT (automático)' : 'Manual',
            'criado_por' => $a->criadoPor?->nome,
            'criado_em' => $this->hora($a->criado_em, $fuso, 'd/m/Y H:i'),
            'atualizado_por' => $a->atualizadoPor?->nome,
            'atualizado_em' => $this->hora($a->atualizado_em, $fuso, 'd/m/Y H:i'),
        ];
    }

    /** Operador altera SÓ a OP de um apontamento (regra única em App\Services\OpDoApontamento, a mesma da Timeline). */
    public function alterarOpDoApontamento(int $id, string $numero): array
    {
        $a = ApontamentoEstado::findOrFail($id);
        $this->conferirMaquina((int) $a->maquina_id); // antes de mexer: só máquina que o usuário enxerga
        app(OpDoApontamento::class)->alterar($a, $numero, 'numero');

        return $this->detalheApontamento($id);
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
     * total do turno e peças por minuto agora (60 ÷ ciclo médio × cavidades). Meta (pç/min) do item MO da estrutura da
     * OP atual (MetaProducao; null sem OP ou sem MO), a meta de cada hora pela OP de cada momento (por_hora[].meta,
     * MetaPorHora) e o molde indicado para conserto (cavidades desativadas abaixo da meta).
     */
    private function producao(Maquina $maquina, ?OrdemProducao $op, ?array $dadosOp, $apontamentos, CarbonImmutable $de, CarbonImmutable $ate, string $fuso): array
    {
        // Reaproveita os apontamentos já lidos (a tela é consultada a cada poucos segundos) e os moldes da OP.
        $porHora = $this->pecas->porHora($maquina, $de, $ate, $fuso, $apontamentos);
        // Meta de CADA hora pela OP de cada momento (a mesma do portal, MetaPorHora): meta (peças), meta até agora,
        // nível da cor (boa ≥ 90%, media ≥ 50%, ruim) e as OPs da hora.
        $agoraTs = now()->getTimestamp();
        $porHora = array_map(fn ($h) => [
            'hora' => $h['hora'], 'inicio' => $h['inicio'], 'pecas' => $h['pecas'], 'meta' => $h['meta'],
            'meta_ate_agora' => $h['metaAteAgora'] === null ? null : (int) round($h['metaAteAgora']),
            'nivel' => ProducaoPorHora::nivelMeta($h + ['futura' => CarbonImmutable::parse($h['inicio'])->getTimestamp() > $agoraTs]),
            'ops' => array_map(fn ($o) => ['op_id' => $o['op_id'], 'numero' => $o['numero'], 'meta_por_minuto' => $o['meta_por_minuto'],
                'meta' => $o['meta'] === null ? null : (int) round($o['meta']), 'pecas' => $o['pecas'], 'minutos' => round($o['segundos'] / 60, 1)], $h['ops']),
        ], app(MetaPorHora::class)->completar($maquina, $porHora, $de, $ate, $fuso, $apontamentos, $op, true,
            app(TurnoDaMaquina::class)->intervalos($maquina)));
        $metaTurno = array_filter(array_column($porHora, 'meta'), fn ($v) => $v !== null);
        $ultimo = $apontamentos->first();
        $ciclo = $ultimo ? ApontamentoEstado::cicloEmSegundos($ultimo->ciclo_medio_ms, $ultimo->ciclo_medio) : null; // s, com fração
        $cavidades = 1;
        foreach ($dadosOp['moldes'] ?? [] as $molde) {
            if ((int) $molde['cavidades_ativas'] > 0) {
                $cavidades = (int) $molde['cavidades_ativas'];
                break;
            }
        }

        $porMinuto = $maquina->situacao === 'produzindo' ? PecasProduzidas::porMinuto($ciclo, $cavidades) : 0.0;
        $meta = app(MetaProducao::class)->porMinuto($op);
        $conserto = null;
        if ($meta !== null && $porMinuto > 0 && $porMinuto < $meta) {
            $molde = collect($dadosOp['moldes'] ?? [])->first(fn ($m) => $m['cavidades'] > 0 && $m['cavidades_ativas'] < $m['cavidades']);
            $conserto = $molde ? ['codigo' => $molde['codigo'], 'descricao' => $molde['descricao'],
                'ativas' => $molde['cavidades_ativas'], 'total' => $molde['cavidades']] : null;
        }

        return [
            'por_hora' => $porHora,
            'pecas_turno' => array_sum(array_column($porHora, 'pecas')),
            'meta_turno' => $metaTurno ? (int) round(array_sum($metaTurno)) : null,
            // ciclo_medio: segundos inteiros (versões antigas do app); ciclo_medio_s: com milissegundos (0.04 = 40 ms)
            'ciclo_medio' => $ciclo === null ? null : (int) round($ciclo),
            'ciclo_medio_s' => $ciclo === null ? null : round($ciclo, 3),
            'ciclo_medio_texto' => Segundos::ciclo($ciclo),
            'cavidades' => $cavidades,
            'pecas_por_minuto' => $porMinuto,
            'meta_por_minuto' => $meta,
            // Molde com cavidades desativadas e produção abaixo da meta: indicar para conserto (null = nada a indicar).
            'molde_conserto' => $conserto,
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
