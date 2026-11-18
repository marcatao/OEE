<?php

namespace App\Services\Manutencao;

use App\Models\Manutencao\ApontamentoHoras;
use App\Models\Manutencao\AreaExecutante;
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
            ->with(['solicitante', 'mantenedores.usuario', 'responsavel' => fn ($q) => $q->withoutGlobalScopes(), 'areaExecutante' => fn ($q) => $q->withoutGlobalScopes(),
                'maquina' => fn ($q) => $q->withoutGlobalScopes()])
            ->get();
        $concluidas = $os()->where('status', 'concluida')->with('solicitante')->orderBy('concluida_em')->get();

        // Encerradas/avaliadas nos últimos 30 dias: tempo médio de atendimento (emissão da OS → conclusão) e avaliações.
        $fechadas = $os()->where('status', 'encerrada')->where('aceite_em', '>=', $desde)->where('aceite_em', '<', $ateExclusivo)
            ->get(['id', 'avaliacao']);
        $avaliacoes = collect(OrdemServico::AVALIACOES)->map(fn ($r, $k) => $fechadas->where('avaliacao', $k)->count());
        $linhas = $this->linhasDeTempo($os()->whereIn('status', ['concluida', 'encerrada'])
            ->where('concluida_em', '>=', $desde)->where('concluida_em', '<', $ateExclusivo));
        $resumoTempos = self::indicadores($linhas);
        // Por área executante acionada no período (com a meta de cada uma) e, com mais de um mês, mês a mês.
        $areas = AreaExecutante::withoutGlobalScopes()->whereIn('id', $linhas->pluck('area_id')->unique())->get()->keyBy('id');
        $temposPorArea = $linhas->groupBy('area_id')->map(fn ($g, $id) => ['area' => $areas[$id] ?? null] + self::indicadores($g))
            ->sortBy(fn ($l) => $l['area']?->nome)->values();
        $meses = [];
        for ($m = $deLocal->startOfMonth(); $m->lte($ateLocal); $m = $m->addMonth()) {
            $meses[$m->format('Y-m')] = ['rotulo' => $m->locale('pt_BR')->translatedFormat('M/y')] + self::indicadores($linhas->where('mes', $m->format('Y-m'))->values());
        }

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
            'porSolicitante' => $this->porSolicitante($abertas),
            'porArea' => $abertas->groupBy(fn ($o) => $o->areaExecutante?->nome ?? '—')->map->count()->sortDesc(),
            'encerradas30' => $fechadas->count(),
            'tempoMedioMin' => $resumoTempos['atendimento'],
            'tempoReparoMin' => $resumoTempos['reparo'],
            'resumoTempos' => $resumoTempos,
            'temposPorArea' => $temposPorArea,
            'temposPorMes' => count($meses) > 1 ? $meses : [],
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
     * Tempos (minutos) das OS concluídas no período, pelo 1º apontamento de horas (o 1º mantenedor começou):
     * atendimento = abertura do chamado (SS; preventiva sem SS: emissão da OS) → 1º apontamento;
     * reparo = 1º apontamento → conclusão. OS sem apontamento ficam fora. Uma linha por OS, com a área executante
     * (e as metas dela) e o mês (local) da conclusão.
     *
     * @return Collection<int, array{area_id: int, mes: string, atendimento: float, reparo: ?float, meta_atendimento: ?int, meta_reparo: ?int}>
     */
    private function linhasDeTempo($consulta): Collection
    {
        $ordens = $consulta->with(['solicitacao:id,criado_em', 'areaExecutante' => fn ($q) => $q->withoutGlobalScopes()])
            ->get(['id', 'solicitacao_id', 'area_executante_id', 'criado_em', 'concluida_em']);
        if ($ordens->isEmpty()) {
            return collect();
        }
        $primeiros = ApontamentoHoras::whereIn('ordem_servico_id', $ordens->pluck('id'))->groupBy('ordem_servico_id')
            ->selectRaw('ordem_servico_id, MIN(inicio) as primeiro')->pluck('primeiro', 'ordem_servico_id');
        $fuso = HorasManutencao::fuso();
        $linhas = collect();
        foreach ($ordens as $o) {
            $abertura = $o->solicitacao?->criado_em ?? $o->criado_em;
            if (! isset($primeiros[$o->id]) || ! $abertura) {
                continue;
            }
            $primeiro = CarbonImmutable::parse($primeiros[$o->id], 'UTC');
            $linhas->push([
                'area_id' => (int) $o->area_executante_id,
                'mes' => $o->concluida_em ? $o->concluida_em->copy()->setTimezone($fuso)->format('Y-m') : $primeiro->setTimezone($fuso)->format('Y-m'),
                'atendimento' => max(0, $primeiro->getTimestamp() - $abertura->getTimestamp()) / 60,
                'reparo' => $o->concluida_em ? max(0, $o->concluida_em->getTimestamp() - $primeiro->getTimestamp()) / 60 : null,
                'meta_atendimento' => $o->areaExecutante?->meta_atendimento_min,
                'meta_reparo' => $o->areaExecutante?->meta_reparo_min,
            ]);
        }

        return $linhas;
    }

    /**
     * Indicadores de um grupo de linhas: quantidade, médias, meta (média das metas das áreas das OS, pesada pelo nº de
     * OS) e quantas ficaram dentro da meta. @return array<string, mixed>
     */
    public static function indicadores(Collection $linhas): array
    {
        $media = fn (string $c) => ($v = $linhas->pluck($c)->filter(fn ($x) => $x !== null))->isNotEmpty() ? $v->avg() : null;
        $dentro = fn (string $c, string $meta) => $linhas->filter(fn ($l) => $l[$meta] !== null && $l[$c] !== null);

        return [
            'os' => $linhas->count(),
            'atendimento' => $media('atendimento'),
            'reparo' => $media('reparo'),
            'meta_atendimento' => $media('meta_atendimento'),
            'meta_reparo' => $media('meta_reparo'),
            'com_meta_atendimento' => $dentro('atendimento', 'meta_atendimento')->count(),
            'dentro_atendimento' => $dentro('atendimento', 'meta_atendimento')->filter(fn ($l) => $l['atendimento'] <= $l['meta_atendimento'])->count(),
            'com_meta_reparo' => $dentro('reparo', 'meta_reparo')->count(),
            'dentro_reparo' => $dentro('reparo', 'meta_reparo')->filter(fn ($l) => $l['reparo'] <= $l['meta_reparo'])->count(),
        ];
    }

    /**
     * OS abertas por SOLICITANTE: os 5 com mais OS (desempate pelas horas apontadas nelas) e, numa 6ª linha "Outras", a
     * soma do resto. Horas = apontadas nessas OS (total_minutos).
     *
     * @return Collection<int, array{solicitante: ?Usuario, nome: string, total: int, porStatus: Collection, minutos: int, outras: bool}>
     */
    private function porSolicitante(Collection $abertas): Collection
    {
        $linha = fn (Collection $g, ?Usuario $quem, string $nome, bool $outras) => [
            'solicitante' => $quem,
            'nome' => $nome,
            'total' => $g->count(),
            'porStatus' => collect(OrdemServico::STATUS_ABERTOS)->mapWithKeys(fn ($s) => [$s => $g->where('status', $s)->count()]),
            'minutos' => (int) $g->sum('total_minutos'),
            'outras' => $outras,
        ];
        $grupos = $abertas->groupBy(fn ($o) => (int) $o->solicitante_id)
            ->map(fn ($g) => $linha($g, $g->first()->solicitante, $g->first()->solicitante?->nome ?? 'Sem solicitante (preventiva)', false))
            ->sortBy([['total', 'desc'], ['minutos', 'desc']])->values();
        $top = $grupos->take(5);
        $resto = $grupos->slice(5);
        if ($resto->isNotEmpty()) {
            $top->push(['solicitante' => null, 'nome' => 'Outras ('.$resto->count().' solicitantes)', 'total' => $resto->sum('total'),
                'porStatus' => collect(OrdemServico::STATUS_ABERTOS)->mapWithKeys(fn ($s) => [$s => $resto->sum(fn ($l) => $l['porStatus'][$s])]),
                'minutos' => $resto->sum('minutos'), 'outras' => true]);
        }

        return $top;
    }
}
