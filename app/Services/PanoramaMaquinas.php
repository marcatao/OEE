<?php

namespace App\Services;

use App\Models\Legenda;
use App\Models\Maquina;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Panorama das máquinas pelos APONTAMENTOS REAIS (os mesmos da Timeline), num período: situação atual (cadastro da
 * máquina), OP, produto e molde, tempo por tipo de legenda e disponibilidade.
 *
 * Disponibilidade (mesma regra da Timeline) = tempo produzindo ÷ (produzindo + paradas não planejadas). Parada
 * planejada e máquina desligada ficam fora; tempo sem apontamento não entra na conta.
 */
class PanoramaMaquinas
{
    public function __construct(private Timeline $timeline) {}

    /**
     * @param  Collection<int, Maquina>  $maquinas
     * @return array{maquinas: array<int, array>, situacoes: array<int, array>, contagem: array<string, int>,
     *     geral: array{disponibilidade: ?float, segundos: array<string, int>, totais: list<array>}}
     */
    public function montar(Collection $maquinas, CarbonInterface $inicio, CarbonInterface $fim): array
    {
        $dados = $this->timeline->montar($maquinas, $inicio, $fim);
        $situacoes = $this->timeline->situacoes($maquinas);

        $porMaquina = [];
        foreach ($maquinas as $m) {
            $segundos = array_fill_keys(array_keys(Legenda::TIPOS), 0);
            $paradas = [];
            $blocos = $dados['linhas'][$m->id] ?? [];
            foreach ($blocos as $b) {
                $s = $b['fim'] - $b['inicio'];
                $tipo = $b['legenda']->tipo;
                $segundos[$tipo] = ($segundos[$tipo] ?? 0) + $s;
                if ($tipo !== 'produzindo') {
                    $paradas[$b['legenda']->id] ??= ['legenda' => $b['legenda'], 'segundos' => 0];
                    $paradas[$b['legenda']->id]['segundos'] += $s;
                }
            }
            usort($paradas, fn ($a, $b) => $b['segundos'] <=> $a['segundos']);
            $base = $segundos['produzindo'] + $segundos['parada'];
            $porMaquina[$m->id] = [
                'segundos' => $segundos,
                'apontado' => array_sum($segundos),
                'disponibilidade' => $base > 0 ? $segundos['produzindo'] / $base : null,
                'maiorParada' => $paradas[0] ?? null,
                'apontamentos' => count($blocos),
                'pendentesOp' => count(array_filter($blocos, fn ($b) => $b['pendenteOp'])),
            ];
        }

        $contagem = ['produzindo' => 0, 'parada' => 0, 'planejada' => 0, 'desligada' => 0, 'sem' => 0];
        foreach ($situacoes as $s) {
            $contagem[$s['estado']] = ($contagem[$s['estado']] ?? 0) + 1;
        }

        return [
            'maquinas' => $porMaquina,
            'situacoes' => $situacoes,
            'contagem' => $contagem,
            'geral' => ['disponibilidade' => $dados['disponibilidade'], 'segundos' => $dados['segundos'], 'totais' => $dados['totais']],
        ];
    }
}
