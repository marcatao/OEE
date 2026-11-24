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
 *
 * Performance (IoT) = peças reais ÷ peças na meta, só dos apontamentos cuja OP tem meta (MetaProducao): peças na meta
 * = meta da OP × tempo PRODUZINDO; peças reais = peças do IoT de todos os apontamentos dessas OPs (qualquer legenda,
 * repartidas pelo tempo dentro do período). OEE (IoT) = disponibilidade × performance (CalculadoraOee::oeeIot).
 *
 * Legenda com "Exibir em dashboards" = Não (pedido do usuário em 24/11/2026): o tempo dela fica FORA daqui — não aparece
 * no tempo por legenda, na barra nem na maior parada e não entra na disponibilidade nem na performance. A Timeline
 * continua mostrando tudo (ela usa a Timeline direto, não este panorama).
 */
class PanoramaMaquinas
{
    public function __construct(private Timeline $timeline, private MetaProducao $metas) {}

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
        $porLegenda = [];
        $porTipo = array_fill_keys(array_keys(Legenda::TIPOS), 0);
        foreach ($maquinas as $m) {
            $segundos = array_fill_keys(array_keys(Legenda::TIPOS), 0);
            $paradas = [];
            $pecas = 0.0;
            $pecasNaMeta = 0.0;
            $blocos = array_values(array_filter($dados['linhas'][$m->id] ?? [], fn ($b) => self::exibe($b['legenda'])));
            foreach ($blocos as $b) {
                $s = $b['fim'] - $b['inicio'];
                $meta = $this->metas->porMinuto($b['apontamento']->ordemProducao, $b['apontamento']->inicio); // a meta da época
                if ($meta !== null) {
                    if ($b['legenda']->tipo === 'produzindo') {
                        $pecasNaMeta += CalculadoraOee::pecasNaMeta($meta, $this->fimDoBloco($b) - $b['inicio']);
                    }
                    $pecas += $this->pecasNoBloco($b);
                }
                $tipo = $b['legenda']->tipo;
                $segundos[$tipo] = ($segundos[$tipo] ?? 0) + $s;
                $porTipo[$tipo] = ($porTipo[$tipo] ?? 0) + $s;
                $porLegenda[$b['legenda']->id] ??= ['legenda' => $b['legenda'], 'segundos' => 0];
                $porLegenda[$b['legenda']->id]['segundos'] += $s;
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
                'disponibilidade' => $disp = ($base > 0 ? $segundos['produzindo'] / $base : null),
                'pecas' => (int) round($pecas),
                'pecasNaMeta' => $pecasNaMeta > 0 ? (int) round($pecasNaMeta) : null,
                'performance' => $perf = CalculadoraOee::performancePelaMeta($pecas, $pecasNaMeta),
                'oeeIot' => CalculadoraOee::oeeIot($disp, $perf),
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
            'geral' => self::geral($porLegenda, $porTipo),
        ];
    }

    /** A legenda aparece (e conta) nos dashboards de máquinas? */
    public static function exibe(?Legenda $legenda): bool
    {
        return $legenda === null || $legenda->exibir_dashboards === null || (bool) $legenda->exibir_dashboards;
    }

    /**
     * Tempo por legenda e disponibilidade do conjunto, só com as legendas exibidas (mesma regra da Timeline).
     *
     * @return array{disponibilidade: ?float, segundos: array<string, int>, totais: list<array>}
     */
    private static function geral(array $porLegenda, array $porTipo): array
    {
        $total = array_sum($porTipo);
        $totais = array_map(fn ($t) => $t + ['pct' => $total > 0 ? $t['segundos'] / $total : 0.0], array_values($porLegenda));
        usort($totais, fn ($a, $b) => [$a['legenda']->ordem, $a['legenda']->codigo] <=> [$b['legenda']->ordem, $b['legenda']->codigo]);
        $base = $porTipo['produzindo'] + $porTipo['parada'];

        return ['disponibilidade' => $base > 0 ? $porTipo['produzindo'] / $base : null, 'segundos' => $porTipo, 'totais' => $totais];
    }

    /** Peças do apontamento dentro do bloco (cortado no período): repartidas pelo tempo do apontamento. */
    private function pecasNoBloco(array $b): float
    {
        $a = $b['apontamento'];
        if (! $a->pecas) {
            return 0.0;
        }
        $ini = $a->inicio->getTimestamp();
        $fim = $a->fim ? $a->fim->getTimestamp() + 1 : max($b['fim'], ($a->atualizado_em ?? now())->getTimestamp());
        $duracao = max(1, $fim - $ini);

        return (float) $a->pecas * min(1.0, ($this->fimDoBloco($b) - $b['inicio']) / $duracao);
    }

    /** Fim do bloco contando o último segundo do apontamento (o fim gravado é o último segundo dele, ex.: 08:59:59). */
    private function fimDoBloco(array $b): int
    {
        $fim = $b['apontamento']->fim?->getTimestamp();

        return $fim !== null && $b['fim'] >= $fim ? $fim + 1 : $b['fim'];
    }
}
