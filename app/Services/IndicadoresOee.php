<?php

namespace App\Services;

use App\Models\Apontamento;
use App\Models\Parada;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * OEE por máquina num período, a partir dos apontamentos de produção e das paradas.
 *
 * Regras (ver .claude/agents/especialista-dados-oee.md):
 * - Entram os apontamentos que COMEÇAM no período; o tempo programado é a duração deles (janelas de produção).
 * - Paradas são cortadas nas janelas dos apontamentos; paradas sobrepostas são unidas antes de somar.
 * - Parada em andamento (fim vazio) conta até agora.
 * - Paradas planejadas (motivo com planejada = 1) saem do tempo planejado; as demais (inclusive sem motivo)
 *   reduzem a disponibilidade.
 * - Performance usa o ciclo ideal gravado em cada apontamento: soma(ciclo × produzido) ÷ tempo operando.
 */
class IndicadoresOee
{
    /**
     * @param  list<int>|null  $maquinas  null = todas
     * @return array<int, IndicadoresPeriodo> indexado pelo id da máquina
     */
    public function porMaquina(CarbonInterface $inicio, CarbonInterface $fim, ?array $maquinas = null): array
    {
        $apontamentos = Apontamento::query()
            ->where('inicio', '>=', $inicio)->where('inicio', '<', $fim)
            ->when($maquinas !== null, fn ($q) => $q->whereIn('maquina_id', $maquinas))
            ->get(['maquina_id', 'inicio', 'fim', 'ciclo_ideal_s', 'total_produzido', 'pecas_boas'])
            ->groupBy('maquina_id');

        if ($apontamentos->isEmpty()) {
            return [];
        }

        $limiteFim = $apontamentos->flatten()->max(fn ($a) => $a->fim);
        $paradas = Parada::query()
            ->with('motivo:id,descricao,planejada')
            ->whereIn('maquina_id', $apontamentos->keys())
            ->where('inicio', '<', $limiteFim)
            ->where(fn ($q) => $q->whereNull('fim')->orWhere('fim', '>', $inicio))
            ->get(['id', 'maquina_id', 'motivo_id', 'inicio', 'fim'])
            ->groupBy('maquina_id');

        $agora = now()->getTimestamp();
        $resultado = [];
        foreach ($apontamentos as $maquinaId => $daMaquina) {
            $resultado[$maquinaId] = $this->calcular($daMaquina, $paradas->get($maquinaId, collect()), $agora);
        }

        return $resultado;
    }

    public function daMaquina(int $maquinaId, CarbonInterface $inicio, CarbonInterface $fim): IndicadoresPeriodo
    {
        return $this->porMaquina($inicio, $fim, [$maquinaId])[$maquinaId] ?? IndicadoresPeriodo::vazio();
    }

    /** Todas as máquinas somadas (tempos e peças somados, nunca média de porcentagens). */
    public function geral(CarbonInterface $inicio, CarbonInterface $fim): IndicadoresPeriodo
    {
        return IndicadoresPeriodo::somar($this->porMaquina($inicio, $fim));
    }

    /**
     * @param  Collection<int, Apontamento>  $apontamentos  de UMA máquina
     * @param  Collection<int, Parada>  $paradas  da mesma máquina
     */
    public function calcular(Collection $apontamentos, Collection $paradas, int $agora): IndicadoresPeriodo
    {
        $janelas = self::unir($apontamentos->map(fn ($a) => [$a->inicio->getTimestamp(), $a->fim->getTimestamp()])->all());

        $planejadas = [];
        $naoPlanejadas = [];
        $pareto = [];
        foreach ($paradas as $parada) {
            $intervalo = [$parada->inicio->getTimestamp(), $parada->fim?->getTimestamp() ?? $agora];
            if ($intervalo[1] <= $intervalo[0]) {
                continue;
            }
            $dentro = self::duracao(self::intersecao([$intervalo], $janelas));
            if ($dentro <= 0) {
                continue;
            }
            $ehPlanejada = (bool) $parada->motivo?->planejada;
            if ($ehPlanejada) {
                $planejadas[] = $intervalo;
            } else {
                $naoPlanejadas[] = $intervalo;
            }

            $motivo = $parada->motivo?->descricao ?? 'Sem justificativa';
            $pareto[$motivo] ??= ['motivo' => $motivo, 'planejada' => $ehPlanejada, 'minutos' => 0.0, 'ocorrencias' => 0];
            $pareto[$motivo]['minutos'] += $dentro / 60;
            $pareto[$motivo]['ocorrencias']++;
        }
        usort($pareto, fn ($a, $b) => $b['minutos'] <=> $a['minutos']);

        $planejadasNaJanela = self::intersecao(self::unir($planejadas), $janelas);
        $naoPlanejadasNaJanela = self::intersecao(self::unir($naoPlanejadas), $janelas);
        // Trecho em que uma parada não planejada coincide com uma planejada conta só como planejada.
        $sobreposicao = self::duracao(self::intersecao($naoPlanejadasNaJanela, $planejadasNaJanela));

        return new IndicadoresPeriodo(
            tempoProgramadoMin: self::duracao($janelas) / 60,
            paradasPlanejadasMin: self::duracao($planejadasNaJanela) / 60,
            paradasNaoPlanejadasMin: (self::duracao($naoPlanejadasNaJanela) - $sobreposicao) / 60,
            producaoIdealMin: $apontamentos->sum(fn ($a) => $a->ciclo_ideal_s * $a->total_produzido) / 60,
            totalProduzido: (int) $apontamentos->sum('total_produzido'),
            pecasBoas: (int) $apontamentos->sum('pecas_boas'),
            apontamentos: $apontamentos->count(),
            pareto: array_values($pareto),
        );
    }

    /**
     * Une intervalos sobrepostos ou encostados.
     *
     * @param  list<array{0: int, 1: int}>  $intervalos
     * @return list<array{0: int, 1: int}>
     */
    public static function unir(array $intervalos): array
    {
        usort($intervalos, fn ($a, $b) => $a[0] <=> $b[0]);
        $unidos = [];
        foreach ($intervalos as [$ini, $fim]) {
            if ($fim <= $ini) {
                continue;
            }
            $ultimo = count($unidos) - 1;
            if ($ultimo >= 0 && $ini <= $unidos[$ultimo][1]) {
                $unidos[$ultimo][1] = max($unidos[$ultimo][1], $fim);
            } else {
                $unidos[] = [$ini, $fim];
            }
        }

        return $unidos;
    }

    /**
     * Trechos em comum entre duas listas de intervalos já unidos (ordenados e sem sobreposição).
     *
     * @return list<array{0: int, 1: int}>
     */
    public static function intersecao(array $a, array $b): array
    {
        $resultado = [];
        $i = $j = 0;
        while ($i < count($a) && $j < count($b)) {
            $ini = max($a[$i][0], $b[$j][0]);
            $fim = min($a[$i][1], $b[$j][1]);
            if ($fim > $ini) {
                $resultado[] = [$ini, $fim];
            }
            if ($a[$i][1] < $b[$j][1]) {
                $i++;
            } else {
                $j++;
            }
        }

        return $resultado;
    }

    /** Soma, em segundos, de intervalos sem sobreposição. */
    public static function duracao(array $intervalos): int
    {
        return array_sum(array_map(fn ($x) => $x[1] - $x[0], $intervalos));
    }
}
