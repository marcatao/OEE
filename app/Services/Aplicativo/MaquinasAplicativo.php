<?php

namespace App\Services\Aplicativo;

use App\Models\ApontamentoEstado;
use App\Models\Ferramenta;
use App\Models\Legenda;
use App\Models\Maquina;
use App\Models\OrdemProducao;
use App\Services\CustoOrdemProducao;
use App\Services\EngenhariaOrdemProducao;
use App\Services\HorarioApontamento;
use App\Services\SituacaoMaquina;
use App\Services\StatusOrdemProducao;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
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

        return $maquinas->map(fn (Maquina $m) => [
            'id' => $m->id,
            'codigo' => rtrim((string) $m->codigo),
            'nome' => $m->nome,
            'local' => $m->localizacao(),
            'situacao' => $m->situacao,
            'legenda' => $this->legenda($m->situacaoLegenda),
            'op' => $m->ordemProducao ? $this->resumoOp($m->ordemProducao, $produtos) : null,
            'sem_motivo' => (int) ($pendentes[$m->id] ?? 0),
        ])->values()->all();
    }

    /** Tudo da tela da máquina: situação, apontamentos recentes, pendências, OP (roteiro e molde) e legendas de motivo. */
    public function detalhe(Maquina $maquina): array
    {
        $this->situacao->desvincularOpsFechadas(array_filter([$maquina->ordem_producao_id]));
        $maquina->refresh()->load(['ordemProducao', 'situacaoLegenda', 'linha.planta']);
        $fuso = HorarioApontamento::fuso();
        $desde = now()->subHours((int) config('oee.app.horas_apontamentos', 24));

        $apontamentos = ApontamentoEstado::with(['legenda', 'ordemProducao'])->where('maquina_id', $maquina->id)
            ->where(fn ($q) => $q->where('inicio', '>=', $desde)->orWhereNull('fim')->orWhere('fim', '>=', $desde))
            ->orderByDesc('inicio')->orderByDesc('id')->limit(200)->get();
        $pendentes = $this->pendentesSemMotivo()->with(['legenda', 'ordemProducao'])->where('maquina_id', $maquina->id)
            ->orderBy('inicio')->get();

        $op = $maquina->ordemProducao;
        $produtos = $op ? $this->custo->produtos([$op->c2_produto]) : collect();

        return [
            'servidor_agora' => now()->toIso8601String(),
            'segundos_atualizacao' => (int) config('oee.app.segundos_atualizacao', 10),
            'maquina' => [
                'id' => $maquina->id,
                'codigo' => rtrim((string) $maquina->codigo),
                'nome' => $maquina->nome,
                'local' => $maquina->localizacao(),
                'situacao' => $maquina->situacao,
                'situacao_desde' => $this->hora($maquina->situacao_desde, $fuso, 'd/m H:i'),
                'legenda' => $this->legenda($maquina->situacaoLegenda),
            ],
            'apontamentos' => $apontamentos->map(fn ($a) => $this->apontamento($a, $fuso))->values()->all(),
            'sem_motivo' => $pendentes->map(fn ($a) => $this->apontamento($a, $fuso))->values()->all(),
            'op' => $op ? $this->op($op, $maquina, $produtos) : null,
            'legendas_motivo' => $this->legendasMotivo()->map(fn ($l) => $this->legenda($l))->values()->all(),
        ];
    }

    /** Troca a legenda "sem motivo" de UM apontamento pela legenda de parada escolhida pelo operador. */
    public function informarMotivo(ApontamentoEstado $apontamento, int $legendaId): void
    {
        $this->conferirMaquina($apontamento->maquina_id);
        if ($apontamento->legenda?->codigo !== $this->codigoSemMotivo()) {
            throw ValidationException::withMessages(['legenda_id' => 'Este apontamento já tem motivo.']);
        }
        $legenda = $this->legendasMotivo()->firstWhere('id', $legendaId);
        if (! $legenda) {
            throw ValidationException::withMessages(['legenda_id' => 'Escolha uma legenda de parada.']);
        }
        $apontamento->update(['legenda_id' => $legenda->id]);
        $this->situacao->aplicar($apontamento->fresh());
        $this->status->atualizar($apontamento->ordemProducao);
    }

    /**
     * Troca a OP da máquina (produção mudou). Regras:
     * - a máquina passa a ter a OP (o próximo apontamento do IoT já vem com ela);
     * - o apontamento em aberto SEM OP recebe a OP;
     * - o apontamento em aberto com OUTRA OP é encerrado 1 s antes de agora e um novo, com a mesma legenda e a OP
     *   nova, começa agora (encadeamento de HorarioApontamento).
     */
    public function trocarOp(Maquina $maquina, string $numero): OrdemProducao
    {
        $op = OrdemProducao::apontaveis()->where('numero', trim($numero))->orderBy('status')->first();
        if (! $op) {
            throw ValidationException::withMessages(['numero' => "Ordem de produção {$numero} não encontrada ou fechada no Protheus."]);
        }
        $afetadas = [$op->id, $maquina->ordem_producao_id];

        DB::transaction(function () use ($maquina, $op, &$afetadas) {
            $aberto = ApontamentoEstado::where('maquina_id', $maquina->id)->whereNull('fim')
                ->orderByDesc('inicio')->orderByDesc('id')->first();
            $maisRecente = ApontamentoEstado::where('maquina_id', $maquina->id)->orderByDesc('inicio')->orderByDesc('id')->value('id');

            if ($aberto && $aberto->id === $maisRecente && $aberto->ordem_producao_id === null) {
                $aberto->update(['ordem_producao_id' => $op->id]);
                $this->situacao->aplicar($aberto);
            } elseif ($aberto && $aberto->id === $maisRecente && $aberto->ordem_producao_id !== $op->id) {
                $agora = CarbonImmutable::now()->startOfSecond();
                if ($agora->subSecond()->lessThanOrEqualTo($aberto->inicio)) {
                    $aberto->update(['ordem_producao_id' => $op->id]); // começou agora: só troca a OP
                    $this->situacao->aplicar($aberto);
                } else {
                    $afetadas[] = $aberto->ordem_producao_id;
                    $aberto->update(['fim' => HorarioApontamento::fimAutomatico($aberto, $agora)]);
                    $novo = ApontamentoEstado::create(['maquina_id' => $maquina->id, 'legenda_id' => $aberto->legenda_id,
                        'ordem_producao_id' => $op->id, 'inicio' => $agora, 'observacao' => 'Troca de OP pelo aplicativo']);
                    $this->situacao->aplicar($novo);
                }
            }
            $maquina->update(['ordem_producao_id' => $op->id]);
        });

        foreach (array_unique(array_filter($afetadas)) as $id) {
            $this->status->atualizar(OrdemProducao::find($id));
        }

        return $op;
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

    /** OPs abertas para escolher (busca pelo número ou pelo produto). */
    public function buscarOps(string $busca): array
    {
        $busca = trim($busca);
        $ops = OrdemProducao::apontaveis()
            ->when($busca !== '', fn ($q) => $q->where(fn ($w) => $w->where('numero', 'like', $busca.'%')->orWhere('c2_produto', 'like', $busca.'%')))
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
        return Legenda::where('ativo', true)->whereIn('tipo', (array) config('oee.app.tipos_legenda_motivo', ['parada']))
            ->where('codigo', '<>', $this->codigoSemMotivo())->orderBy('ordem')->orderBy('codigo')->get();
    }

    private function legenda(?Legenda $l): ?array
    {
        return $l ? ['id' => $l->id, 'codigo' => $l->codigo, 'descricao' => $l->descricao, 'tipo' => $l->tipo,
            'cor' => $l->cor, 'exige_op' => (bool) $l->exige_op] : null;
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
        ];
    }

    private function op(OrdemProducao $op, Maquina $maquina, $produtos): array
    {
        $roteiro = $this->engenharia->roteiro($op);
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
