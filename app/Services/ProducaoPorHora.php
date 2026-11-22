<?php

namespace App\Services;

use App\Models\ApontamentoEstado;
use App\Models\Maquina;
use App\Models\OrdemProducao;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Dashboards › Produção por hora: para cada máquina, as peças contadas pelo IoT em cada hora do turno do dia (as
 * peças de cada apontamento repartidas pelo tempo dele em cada hora — PecasProduzidas::porHora, o mesmo gráfico do
 * app), o total do turno, as OPs que produziram no turno (com o produto) e a situação agora (OP atual, produto,
 * molde, cavidades e peças por minuto, de Timeline::situacoes).
 *
 * Meta em peças por minuto: ainda não existe (em breve); com ela, cada hora ganha a meta (meta × 60) e o % atingido.
 */
class ProducaoPorHora
{
    public function __construct(
        private TurnoDaMaquina $turno,
        private PecasProduzidas $pecas,
        private Timeline $timeline,
        private CustoOrdemProducao $custo,
    ) {}

    /**
     * @param  Collection<int, Maquina>  $maquinas
     * @return array{maquinas: list<array>, geral: array}
     */
    public function montar(Collection $maquinas, CarbonImmutable $dia): array
    {
        $fuso = HorarioApontamento::fuso();
        $agora = CarbonImmutable::now();
        if ($maquinas->isEmpty()) {
            return ['maquinas' => [], 'geral' => $this->geral([], $agora)];
        }

        $periodos = [];
        foreach ($maquinas as $m) {
            $periodos[(int) $m->id] = $this->turno->periodo($m, $dia);
        }
        $de = collect($periodos)->min(fn ($p) => $p[0]->getTimestamp());
        $ate = collect($periodos)->max(fn ($p) => $p[1]->getTimestamp());

        // Uma consulta para todas as máquinas (pelo índice do período); cada máquina usa só os seus.
        $lidos = ApontamentoEstado::whereIntegerInRaw('maquina_id', $maquinas->map(fn ($m) => (int) $m->id)->all())
            ->whereNotNull('pecas')->where('pecas', '>', 0)
            ->tocando(CarbonImmutable::createFromTimestamp($de), CarbonImmutable::createFromTimestamp($ate))
            ->get(['id', 'maquina_id', 'ordem_producao_id', 'inicio', 'fim', 'pecas', 'atualizado_em'])
            ->groupBy(fn ($a) => (int) $a->maquina_id);

        $situacoes = $this->timeline->situacoes($maquinas);
        $opIds = $lidos->flatten()->pluck('ordem_producao_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $ops = $opIds->isEmpty() ? collect() : OrdemProducao::whereIntegerInRaw('id', $opIds->all())->get()->keyBy(fn ($op) => (int) $op->id);
        $produtos = $this->custo->produtos($ops->pluck('c2_produto')
            ->merge($maquinas->pluck('ordemProducao')->filter()->pluck('c2_produto')));

        $resultado = [];
        foreach ($maquinas as $m) {
            $id = (int) $m->id;
            [$inicio, $fim, $rotulo] = $periodos[$id];
            $aps = $lidos->get($id, collect());
            $sit = $situacoes[$id] ?? [];
            $metaPorMinuto = null; // em breve
            $horas = $this->horas($this->pecas->porHora($m, $inicio, $fim, $fuso, $aps), $fim, $agora, $metaPorMinuto);

            // OPs que produziram no turno (peças repartidas pelo tempo dentro do turno, como as horas).
            $porOp = [];
            foreach ($aps->groupBy(fn ($a) => (int) $a->ordem_producao_id) as $opId => $grupo) {
                $total = array_sum(array_column($this->pecas->porHora($m, $inicio, $fim, $fuso, $grupo), 'pecas'));
                if ($total <= 0) {
                    continue;
                }
                $op = $opId ? $ops->get($opId) : null;
                $porOp[] = ['op' => $op, 'produto' => $op ? $produtos->get(rtrim((string) $op->c2_produto)) : null, 'pecas' => $total];
            }
            usort($porOp, fn ($a, $b) => $b['pecas'] <=> $a['pecas']);

            $total = array_sum(array_column($horas, 'pecas'));
            $opAtual = $sit['op'] ?? null;
            $resultado[] = [
                'maquina' => $m,
                'situacao' => $sit,
                'rotulo' => $rotulo,
                'inicio' => $inicio,
                'fim' => $fim,
                'aoVivo' => $agora->greaterThanOrEqualTo($inicio) && $agora->lessThan($fim),
                'horas' => $horas,
                'total' => $total,
                'melhorHora' => $total > 0 ? max(array_column($horas, 'pecas')) : 0,
                'mediaHora' => $this->mediaPorHora($horas),
                'cavidades' => PecasProduzidas::cavidadesDosMoldes($sit['moldes'] ?? []),
                'cicloMedio' => $sit['cicloMedio'] ?? null,
                'pecasPorMinuto' => (float) ($sit['pecasPorMinuto'] ?? 0),
                'metaPorMinuto' => $metaPorMinuto,
                'metaTurno' => $metaPorMinuto !== null ? (int) round(array_sum(array_column($horas, 'meta'))) : null,
                'produto' => $opAtual ? ($produtos->get(rtrim((string) $opAtual->c2_produto)) ?? ($sit['produto'] ?? null)) : null,
                'ops' => $porOp,
            ];
        }

        return ['maquinas' => $resultado, 'geral' => $this->geral($resultado, $agora)];
    }

    /**
     * Horas do gráfico com a hora atual e as futuras marcadas; com meta, a meta da hora (meta × minutos da hora no turno).
     *
     * @param  list<array{hora: string, inicio: string, pecas: int}>  $porHora
     */
    private function horas(array $porHora, CarbonImmutable $fim, CarbonImmutable $agora, ?float $metaPorMinuto): array
    {
        $agoraTs = $agora->getTimestamp();

        return array_map(function ($h) use ($agoraTs, $metaPorMinuto, $fim) {
            $ini = CarbonImmutable::parse($h['inicio'])->getTimestamp();
            $fimHora = min($ini + 3600, $fim->getTimestamp());

            return $h + [
                'atual' => $agoraTs >= $ini && $agoraTs < $fimHora,
                'futura' => $agoraTs < $ini,
                'meta' => $metaPorMinuto !== null ? round($metaPorMinuto * max(0, $fimHora - $ini) / 60) : null,
            ];
        }, $porHora);
    }

    /** Média por hora das horas já passadas (sem a atual, que ainda não fechou; sem nenhuma passada, a atual). */
    private function mediaPorHora(array $horas): ?float
    {
        $passadas = array_filter($horas, fn ($h) => ! $h['atual'] && ! $h['futura']);
        if (! $passadas) {
            $passadas = array_filter($horas, fn ($h) => $h['atual']);
        }

        return $passadas ? round(array_sum(array_column($passadas, 'pecas')) / count($passadas), 1) : null;
    }

    /** Resumo de todas as máquinas: total de peças, produzindo agora, peças por minuto somadas e peças por hora do relógio. */
    private function geral(array $maquinas, CarbonImmutable $agora): array
    {
        $porHora = [];
        foreach ($maquinas as $d) {
            foreach ($d['horas'] as $h) {
                $porHora[$h['inicio']] ??= ['hora' => $h['hora'], 'inicio' => $h['inicio'], 'pecas' => 0, 'atual' => false, 'futura' => true, 'meta' => null];
                $porHora[$h['inicio']]['pecas'] += $h['pecas'];
                $porHora[$h['inicio']]['atual'] = $porHora[$h['inicio']]['atual'] || $h['atual'];
                $porHora[$h['inicio']]['futura'] = $porHora[$h['inicio']]['futura'] && $h['futura'];
            }
        }
        ksort($porHora);

        return [
            'maquinas' => count($maquinas),
            'produzindo' => count(array_filter($maquinas, fn ($d) => ($d['situacao']['estado'] ?? null) === 'produzindo')),
            'total' => array_sum(array_column($maquinas, 'total')),
            'pecasPorMinuto' => round(array_sum(array_column($maquinas, 'pecasPorMinuto')), 1),
            'horas' => array_values($porHora),
            'aoVivo' => (bool) array_filter($maquinas, fn ($d) => $d['aoVivo']),
            'atualizadoEm' => $agora->setTimezone(HorarioApontamento::fuso()),
        ];
    }
}
