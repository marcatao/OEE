<?php

namespace App\Services;

use App\Models\ApontamentoEstado;
use App\Models\Legenda;
use App\Models\Maquina;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Monta a timeline: para cada máquina, os blocos de estado (legenda) dentro da janela, já cortados nos limites
 * e com a posição em % para desenhar. Também soma o tempo por legenda e calcula a disponibilidade da janela.
 *
 * Disponibilidade (timeline) = tempo produzindo ÷ (produzindo + paradas não planejadas). Paradas planejadas e
 * máquina desligada ficam fora do tempo planejado; tempo sem apontamento não entra na conta.
 */
class Timeline
{
    /**
     * @param  Collection<int, Maquina>  $maquinas
     * @return array{linhas: array<int, list<array>>, totais: list<array>, disponibilidade: ?float, segundos: array<string, int>}
     */
    public function montar(Collection $maquinas, CarbonInterface $inicio, CarbonInterface $fim): array
    {
        $ini = $inicio->getTimestamp();
        $fimJanela = $fim->getTimestamp();
        $agora = now()->getTimestamp();
        $duracao = max($fimJanela - $ini, 1);

        $apontamentos = ApontamentoEstado::with('legenda')
            ->whereIn('maquina_id', $maquinas->modelKeys())
            ->where('inicio', '<', $fim)
            ->where(fn ($q) => $q->whereNull('fim')->orWhere('fim', '>', $inicio))
            ->orderBy('inicio')
            ->get()
            ->groupBy('maquina_id');

        $linhas = [];
        $porLegenda = [];
        $porTipo = array_fill_keys(array_keys(Legenda::TIPOS), 0);

        foreach ($maquinas as $maquina) {
            $blocos = [];
            foreach ($apontamentos->get($maquina->id, collect()) as $a) {
                $a0 = max($a->inicio->getTimestamp(), $ini);
                $a1 = min($a->fim?->getTimestamp() ?? min($agora, $fimJanela), $fimJanela);
                if ($a1 <= $a0) {
                    continue;
                }

                $blocos[] = [
                    'apontamento' => $a,
                    'legenda' => $a->legenda,
                    'inicio' => $a0,
                    'fim' => $a1,
                    'esquerda' => round(($a0 - $ini) / $duracao * 100, 4),
                    'largura' => round(($a1 - $a0) / $duracao * 100, 4),
                    'aberto' => $a->fim === null,
                ];

                $id = $a->legenda_id;
                $porLegenda[$id] ??= ['legenda' => $a->legenda, 'segundos' => 0];
                $porLegenda[$id]['segundos'] += $a1 - $a0;
                $porTipo[$a->legenda->tipo] = ($porTipo[$a->legenda->tipo] ?? 0) + ($a1 - $a0);
            }
            $linhas[$maquina->id] = $blocos;
        }

        $totalApontado = array_sum($porTipo);
        $totais = array_map(fn ($t) => $t + ['pct' => $totalApontado > 0 ? $t['segundos'] / $totalApontado : 0.0], array_values($porLegenda));
        usort($totais, fn ($a, $b) => [$a['legenda']->ordem, $a['legenda']->codigo] <=> [$b['legenda']->ordem, $b['legenda']->codigo]);

        $base = $porTipo['produzindo'] + $porTipo['parada'];

        return [
            'linhas' => $linhas,
            'totais' => $totais,
            'disponibilidade' => $base > 0 ? $porTipo['produzindo'] / $base : null,
            'segundos' => $porTipo,
        ];
    }

    /**
     * Marcas de hora do eixo (uma por hora cheia dentro da janela).
     *
     * @return list<array{rotulo: string, esquerda: float}>
     */
    public function marcas(CarbonInterface $inicio, CarbonInterface $fim): array
    {
        $fuso = config('oee.fuso_exibicao');
        $duracao = max($fim->getTimestamp() - $inicio->getTimestamp(), 1);
        $hora = $inicio->copy()->setTimezone($fuso);
        if ($hora->minute || $hora->second) {
            $hora = $hora->addHour()->startOfHour();
        }

        $marcas = [];
        while ($hora->getTimestamp() < $fim->getTimestamp()) {
            $marcas[] = [
                'rotulo' => $hora->format('H:i'),
                'esquerda' => round(($hora->getTimestamp() - $inicio->getTimestamp()) / $duracao * 100, 4),
                'virada' => $hora->format('H:i') === '00:00',
            ];
            $hora = $hora->addHour();
        }

        return $marcas;
    }
}
