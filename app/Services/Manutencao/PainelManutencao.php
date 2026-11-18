<?php

namespace App\Services\Manutencao;

use App\Models\Manutencao\ApontamentoHoras;
use App\Models\Manutencao\InteracaoOs;
use App\Models\Manutencao\Mantenedor;
use App\Models\Manutencao\OrdemServico;
use App\Models\Manutencao\SolicitacaoServico;
use App\Models\Usuario;
use App\Support\AcessoOs;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Painel das Ordens de serviço de UMA área (unidade: manutencao, tecnologia ou ferramentaria), só com o que o usuário pode ver
 * (AcessoOs::filtrar): chamados aguardando aprovação, OS abertas e por mantenedor, máquinas paradas, mensagens sem
 * resposta, aguardando avaliação, preventivas atrasadas e os números dos últimos 30 dias.
 */
final class PainelManutencao
{
    /**
     * Período dos números (De/Até, dias locais; padrão: o mês atual até hoje): OS encerradas e avaliações (pela data da
     * avaliação), tempos de atendimento e de reparo (OS concluídas no período) e horas apontadas. O resto é "agora".
     *
     * @return array<string, mixed>
     */
    public function dados(Usuario $u, string $unidade, ?CarbonImmutable $deLocal = null, ?CarbonImmutable $ateLocal = null): array
    {
        $hojeLocal = CarbonImmutable::now(HorasManutencao::fuso())->startOfDay();
        $deLocal = ($deLocal ?? $hojeLocal->startOfMonth())->startOfDay();
        $ateLocal = ($ateLocal ?? $hojeLocal)->startOfDay();
        $desde = $deLocal->utc();
        $ateExclusivo = $ateLocal->addDay()->utc();

        $os = fn () => AcessoOs::filtrar(OrdemServico::query(), $u)->where('ordens_servico.unidade', $unidade);
        $ss = fn () => AcessoOs::filtrar(SolicitacaoServico::query(), $u)->where('solicitacoes_servico.unidade', $unidade);

        // SS aguardando aprovação (as mais antigas primeiro).
        $pendentes = $ss()->where('status', 'pendente')->with(['solicitante', 'areaExecutante' => fn ($q) => $q->withoutGlobalScopes(),
            'maquina' => fn ($q) => $q->withoutGlobalScopes()])->orderBy('id')->get();

        // OS abertas (carregadas uma vez: as contas por status, mantenedor e área saem daqui).
        $abertas = $os()->whereIn('status', OrdemServico::STATUS_ABERTOS)
            ->with(['mantenedores.usuario', 'responsavel' => fn ($q) => $q->withoutGlobalScopes(), 'areaExecutante' => fn ($q) => $q->withoutGlobalScopes(),
                'maquina' => fn ($q) => $q->withoutGlobalScopes()])
            ->get();
        $concluidas = $os()->where('status', 'concluida')->with('solicitante')->orderBy('concluida_em')->get();

        // Encerradas/avaliadas nos últimos 30 dias: tempo médio de atendimento (emissão da OS → conclusão) e avaliações.
        $fechadas = $os()->where('status', 'encerrada')->where('aceite_em', '>=', $desde)->where('aceite_em', '<', $ateExclusivo)
            ->get(['id', 'avaliacao']);
        $avaliacoes = collect(OrdemServico::AVALIACOES)->map(fn ($r, $k) => $fechadas->where('avaliacao', $k)->count());
        [$atendimento, $reparo] = $this->tempos($os()->whereIn('status', ['concluida', 'encerrada'])
            ->where('concluida_em', '>=', $desde)->where('concluida_em', '<', $ateExclusivo));

        $minutosMes = (int) ApontamentoHoras::whereNotNull('fim')->where('inicio', '>=', $desde)->where('inicio', '<', $ateExclusivo)
            ->whereIn('ordem_servico_id', $os()->select('ordens_servico.id'))
            ->sum(DB::raw('COALESCE(minutos_normais, 0) + COALESCE(minutos_extras, 0)'));

        return [
            'pendentes' => $pendentes,
            'abertas' => $abertas,
            'porStatus' => collect(OrdemServico::STATUS_ABERTOS)->mapWithKeys(fn ($s) => [$s => $abertas->where('status', $s)->count()]),
            'maquinasParadas' => $abertas->where('maquina_parada', true)->pluck('maquina_id')->filter()->unique()->count(),
            'urgentes' => $abertas->whereIn('prioridade', SolicitacaoServico::PRIORIDADES_ALTAS)->count(),
            'semMantenedor' => $abertas->filter(fn ($o) => $o->responsavel_id === null && $o->mantenedores->isEmpty())->values(),
            'preventivasAtrasadas' => $abertas->filter(fn ($o) => $o->tipo === 'preventiva' && $o->prevista_para && $o->prevista_para->lt($hojeLocal))->values(),
            'aguardandoAvaliacao' => $concluidas,
            'semResposta' => $this->semResposta($abertas->merge($concluidas), $unidade),
            'porMantenedor' => $this->porMantenedor($abertas, $unidade, $desde, $ateExclusivo),
            'porArea' => $abertas->groupBy(fn ($o) => $o->areaExecutante?->nome ?? '—')->map->count()->sortDesc(),
            'encerradas30' => $fechadas->count(),
            'tempoMedioMin' => $atendimento,
            'tempoReparoMin' => $reparo,
            'periodoDe' => $deLocal,
            'periodoAte' => $ateLocal,
            'avaliacoes' => $avaliacoes,
            'minutosMes' => $minutosMes,
            'urgentesLista' => $abertas->filter(fn ($o) => in_array($o->prioridade, SolicitacaoServico::PRIORIDADES_ALTAS, true) || $o->maquina_parada || $o->risco_integridade)->sortBy('id')->values(),
        ];
    }

    /**
     * OS cuja ÚLTIMA mensagem é do solicitante ou de alguém fora da equipe (mantenedor cadastrado ou administrador da
     * área): alguém perguntou e ninguém da manutenção respondeu ainda.
     *
     * @return Collection<int, array{os: OrdemServico, msg: InteracaoOs}>
     */
    private function semResposta(Collection $ordens, string $unidade): Collection
    {
        if ($ordens->isEmpty()) {
            return collect();
        }
        $ultimas = InteracaoOs::whereIn('id', InteracaoOs::query()->selectRaw('MAX(id)')->whereIn('ordem_servico_id', $ordens->pluck('id'))->groupBy('ordem_servico_id'))
            ->with('usuario')->get();
        $equipe = Mantenedor::withoutGlobalScopes()->whereNotNull('usuario_id')->pluck('usuario_id')->map(fn ($i) => (int) $i)->all();
        $porId = $ordens->keyBy('id');

        // Sem resposta: a última mensagem é do próprio solicitante (mesmo que ele seja da equipe) ou de alguém de fora da equipe.
        return $ultimas->filter(fn ($m) => (int) $m->usuario_id === (int) $porId[$m->ordem_servico_id]->solicitante_id
            || (! in_array((int) $m->usuario_id, $equipe, true) && ! ($m->usuario && AcessoOs::ehAdminDa($m->usuario, $unidade))))
            ->sortBy('id')
            ->map(fn ($m) => ['os' => $porId[$m->ordem_servico_id], 'msg' => $m])
            ->values();
    }

    /**
     * OS abertas de cada mantenedor da área (atribuído ou responsável), por status, horas no mês e se está apontando agora.
     *
     * @return Collection<int, array<string, mixed>>
     */
    /**
     * Tempos médios (minutos) das OS concluídas no período, pelo 1º apontamento de horas (o 1º mantenedor começou):
     * atendimento = abertura do chamado (SS; preventiva sem SS: emissão da OS) → 1º apontamento;
     * reparo = 1º apontamento → conclusão. OS sem apontamento ficam fora. @return array{0: ?float, 1: ?float}
     */
    private function tempos($consulta): array
    {
        $ordens = $consulta->with('solicitacao:id,criado_em')->get(['id', 'solicitacao_id', 'criado_em', 'concluida_em']);
        if ($ordens->isEmpty()) {
            return [null, null];
        }
        $primeiros = ApontamentoHoras::whereIn('ordem_servico_id', $ordens->pluck('id'))->groupBy('ordem_servico_id')
            ->selectRaw('ordem_servico_id, MIN(inicio) as primeiro')->pluck('primeiro', 'ordem_servico_id');
        $atendimento = collect();
        $reparo = collect();
        foreach ($ordens as $o) {
            if (! isset($primeiros[$o->id])) {
                continue;
            }
            $primeiro = CarbonImmutable::parse($primeiros[$o->id], 'UTC');
            $abertura = $o->solicitacao?->criado_em ?? $o->criado_em;
            if ($abertura) {
                $atendimento->push(max(0, $primeiro->getTimestamp() - $abertura->getTimestamp()) / 60);
            }
            if ($o->concluida_em) {
                $reparo->push(max(0, $o->concluida_em->getTimestamp() - $primeiro->getTimestamp()) / 60);
            }
        }

        return [$atendimento->isNotEmpty() ? $atendimento->avg() : null, $reparo->isNotEmpty() ? $reparo->avg() : null];
    }

    private function porMantenedor(Collection $abertas, string $unidade, CarbonImmutable $desde, CarbonImmutable $ateExclusivo): Collection
    {
        $mantenedores = Mantenedor::withoutGlobalScopes()->where('ativo', true)->with(['usuario', 'areas'])->orderBy('nome')->get()
            ->filter(fn ($m) => $m->areas->isNotEmpty() ? $m->areas->contains(fn ($a) => in_array($a->unidade, [$unidade, 'ambos'], true))
                : in_array($m->unidade, [$unidade, 'ambos'], true));
        $ids = $mantenedores->pluck('id');
        $minutos = ApontamentoHoras::whereIn('mantenedor_id', $ids)->whereNotNull('fim')->where('inicio', '>=', $desde)->where('inicio', '<', $ateExclusivo)
            ->groupBy('mantenedor_id')->selectRaw('mantenedor_id, SUM(COALESCE(minutos_normais, 0) + COALESCE(minutos_extras, 0)) as total')
            ->pluck('total', 'mantenedor_id');
        $rodando = ApontamentoHoras::whereIn('mantenedor_id', $ids)->whereNull('fim')->with('ordemServico:id')->get()->keyBy('mantenedor_id');

        return $mantenedores->map(function ($m) use ($abertas, $minutos, $rodando) {
            $dele = $abertas->filter(fn ($o) => (int) $o->responsavel_id === $m->id || $o->mantenedores->contains('id', $m->id));

            return [
                'mantenedor' => $m,
                'total' => $dele->count(),
                'porStatus' => collect(OrdemServico::STATUS_ABERTOS)->mapWithKeys(fn ($s) => [$s => $dele->where('status', $s)->count()]),
                'urgentes' => $dele->whereIn('prioridade', SolicitacaoServico::PRIORIDADES_ALTAS)->count(),
                'minutosMes' => (int) ($minutos[$m->id] ?? 0),
                'rodando' => $rodando[$m->id] ?? null,
            ];
        })->sortByDesc('total')->values();
    }
}
