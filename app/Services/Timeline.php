<?php

namespace App\Services;

use App\Models\ApontamentoEstado;
use App\Models\Legenda;
use App\Models\Maquina;
use App\Models\OrdemProducao;
use App\Models\ProdutoProtheus;
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
     * Situação atual de cada máquina, lida do CADASTRO da máquina (campos situacao e ordem_producao_id, atualizados pelo
     * cadastro e pelo apontamento real mais recente). Produzindo ou parada; a legenda que deu a situação só refina a
     * cor (parada planejada em âmbar, desligada em cinza). Traz a OP atual e o produto dela.
     *
     * @param  Collection<int, Maquina>  $maquinas
     * @return array<int, array{estado: string, rotulo: string, legenda: ?Legenda, desde: ?CarbonInterface,
     *     op: ?OrdemProducao, produto: ?ProdutoProtheus}>
     */
    public function situacoes(Collection $maquinas): array
    {
        $maquinas->loadMissing(['ordemProducao', 'situacaoLegenda']);
        $produtos = app(CustoOrdemProducao::class)
            ->produtos($maquinas->pluck('ordemProducao')->filter()->map(fn ($op) => $op->c2_produto));

        $situacoes = [];
        foreach ($maquinas as $m) {
            $legenda = $m->situacaoLegenda;
            [$estado, $rotulo] = match (true) {
                $m->situacao === 'produzindo' => ['produzindo', 'Produzindo'],
                $m->situacao === 'parada' && $legenda?->tipo === 'planejada' => ['planejada', 'Parada planejada'],
                $m->situacao === 'parada' && $legenda?->tipo === 'desligada' => ['desligada', 'Desligada'],
                $m->situacao === 'parada' => ['parada', 'Parada'],
                default => ['sem', 'Sem informação'],
            };
            $op = $m->ordemProducao;
            $situacoes[$m->id] = [
                'estado' => $estado,
                'rotulo' => $rotulo,
                'legenda' => $legenda,
                'desde' => $m->situacao_desde,
                'op' => $op,
                'produto' => $op ? ($produtos[rtrim($op->c2_produto)] ?? null) : null,
            ];
        }

        return $situacoes;
    }

    /**
     * @param  Collection<int, Maquina>  $maquinas
     * @return array{linhas: array<int, list<array>>, totais: list<array>, disponibilidade: ?float, segundos: array<string, int>, pendentesOp: int}
     */
    public function montar(Collection $maquinas, CarbonInterface $inicio, CarbonInterface $fim): array
    {
        $ini = $inicio->getTimestamp();
        $fimJanela = $fim->getTimestamp();
        $agora = now()->getTimestamp();
        $duracao = max($fimJanela - $ini, 1);

        $apontamentos = ApontamentoEstado::with(['legenda', 'ordemProducao'])
            ->whereIn('maquina_id', $maquinas->modelKeys())
            ->where('inicio', '<', $fim)
            ->where(fn ($q) => $q->whereNull('fim')->orWhere('fim', '>', $inicio))
            ->orderBy('inicio')
            ->get()
            ->groupBy('maquina_id');

        $linhas = [];
        $pendentesOp = 0;
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
                    // Legenda exige OP e o apontamento está sem: pisca na timeline com "Informar OP".
                    'pendenteOp' => (bool) $a->legenda?->exige_op && $a->ordem_producao_id === null,
                ];
                $pendentesOp += (int) end($blocos)['pendenteOp'];

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
            'pendentesOp' => $pendentesOp,
        ];
    }

    /**
     * Marcas do eixo. O espaçamento acompanha o tamanho do período: de hora em hora até ~1 dia, depois de 3, 6 ou
     * 12 horas, e de dia em dia em períodos longos. Na virada do dia a marca mostra a data (dd/mm).
     *
     * @return list<array{rotulo: string, esquerda: float, virada: bool}>
     */
    public function marcas(CarbonInterface $inicio, CarbonInterface $fim): array
    {
        $fuso = config('oee.fuso_exibicao');
        $duracao = max($fim->getTimestamp() - $inicio->getTimestamp(), 1);
        $horas = $duracao / 3600;
        $passo = match (true) {
            $horas <= 26 => 1,
            $horas <= 3 * 24 => 3,
            $horas <= 7 * 24 => 6,
            $horas <= 14 * 24 => 12,
            default => 24,
        };

        $hora = $inicio->copy()->setTimezone($fuso);
        if ($hora->minute || $hora->second) {
            $hora = $hora->addHour()->startOfHour();
        }
        while ($hora->hour % $passo !== 0) {
            $hora = $hora->addHour();
        }

        $marcas = [];
        while ($hora->getTimestamp() < $fim->getTimestamp()) {
            $virada = $hora->format('H:i') === '00:00';
            $marcas[] = [
                'rotulo' => $virada && $horas > 26 ? $hora->format('d/m') : $hora->format('H:i'),
                'esquerda' => round(($hora->getTimestamp() - $inicio->getTimestamp()) / $duracao * 100, 4),
                'virada' => $virada,
            ];
            $hora = $hora->addHours($passo);
        }

        return $marcas;
    }
}
