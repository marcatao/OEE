<?php

namespace App\Services;

use App\Models\Maquina;
use App\Models\MetaHora;
use App\Models\OrdemProducao;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Meta de cada HORA do turno pela OP que a máquina tinha em cada momento (pedido do usuário em 24/11/2026).
 *
 * - O turno é dividido nos trechos dos apontamentos reais da máquina; cada trecho vale para a OP do apontamento (sem
 *   OP: a OP do trecho anterior; antes do 1º com OP, a do 1º). A meta do trecho = meta (pç/min) da OP NAQUELE MOMENTO
 *   (MetaProducao::porMinuto($op, início do trecho), histórico em metas_producao) × minutos do trecho.
 * - Trocou a OP no meio da hora (ex.: 08:30): a meta da hora é proporcional — metade da OP 1 e metade da OP 2.
 * - Depois do último apontamento até o fim do turno (horas que ainda vão chegar): a OP atual da máquina com a meta de
 *   agora. Trocar a OP só muda a meta dali para frente; as horas que já passaram ficam com a meta delas.
 * - Meta e peças de cada hora, por OP, ficam gravadas em metas_producao_horas (MetaHora): só as horas que já
 *   começaram e só o que mudou.
 */
class MetaPorHora
{
    public function __construct(private MetaProducao $metas, private PecasProduzidas $pecas) {}

    /**
     * Completa as horas de PecasProduzidas::porHora com a meta da hora (meta), a meta até agora (metaAteAgora) e as OPs
     * da hora (ops: op_id, numero, meta_por_minuto, segundos, meta, pecas).
     *
     * @param  list<array{hora: string, inicio: string, pecas: int}>  $horas
     * @param  iterable  $apontamentos  apontamentos da máquina que tocam o turno (inicio, fim, ordem_producao_id, pecas, atualizado_em)
     * @param  list<array{de: CarbonImmutable, ate: CarbonImmutable}>|null  $turnos  turnos do dia (TurnoDaMaquina::intervalos):
     *                                                                               a meta só conta DENTRO deles (intervalo entre turnos = sem meta); null/vazio = o período inteiro
     * @return list<array>
     */
    public function completar(Maquina $maquina, array $horas, CarbonImmutable $de, CarbonImmutable $ate, string $fuso,
        iterable $apontamentos, ?OrdemProducao $opAtual, bool $gravar = true, ?array $turnos = null): array
    {
        $agora = CarbonImmutable::now()->getTimestamp();
        $aps = collect($apontamentos)->sortBy(fn ($a) => [$a->inicio->getTimestamp(), (int) $a->id])->values();
        $opIds = $aps->pluck('ordem_producao_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $ops = $opIds->isEmpty() ? collect() : OrdemProducao::whereIntegerInRaw('id', $opIds->all())->get()->keyBy(fn ($op) => (int) $op->id);
        if ($opAtual) {
            $ops->put((int) $opAtual->id, $opAtual);
        }

        $trechos = $this->trechos($aps, $de->getTimestamp(), $ate->getTimestamp(), $agora, $opAtual ? (int) $opAtual->id : null);
        if ($turnos) {
            // Só as horas disponíveis (dentro dos turnos) têm meta: corta cada trecho nos turnos.
            $cortados = [];
            foreach ($trechos as $t) {
                foreach ($turnos as $tu) {
                    $ini = max($t['inicio'], $tu['de']->getTimestamp());
                    $fim = min($t['fim'], $tu['ate']->getTimestamp());
                    if ($fim > $ini) {
                        $cortados[] = ['inicio' => $ini, 'fim' => $fim] + $t;
                    }
                }
            }
            $trechos = $cortados;
        }

        // Peças de cada OP por hora (as mesmas regras do gráfico: repartidas pelo tempo de cada apontamento).
        $pecasPorOp = [];
        foreach ($aps->filter(fn ($a) => $a->pecas > 0)->groupBy(fn ($a) => (int) $a->ordem_producao_id) as $opId => $grupo) {
            foreach ($this->pecas->porHora($maquina, $de, $ate, $fuso, $grupo) as $h) {
                $pecasPorOp[$h['inicio']][(int) $opId] = $h['pecas'];
            }
        }

        $fimTurno = $ate->getTimestamp();
        $inicioTurno = $de->getTimestamp();
        foreach ($horas as &$h) {
            $ini = max(CarbonImmutable::parse($h['inicio'])->getTimestamp(), $inicioTurno);
            $fim = min(CarbonImmutable::parse($h['inicio'])->getTimestamp() + 3600, $fimTurno);
            $porOp = [];
            $meta = null;
            $ateAgora = null;
            foreach ($trechos as $t) {
                $seg = min($fim, $t['fim']) - max($ini, $t['inicio']);
                if ($seg <= 0) {
                    continue;
                }
                $op = $t['op'] !== null ? $ops->get($t['op']) : null;
                $porMinuto = $op ? $this->metas->porMinuto($op, $t['futuro'] ? null : CarbonImmutable::createFromTimestamp($t['inicio'])) : null;
                $chave = $t['op'] ?? 0;
                $porOp[$chave] ??= ['op_id' => $t['op'], 'numero' => $op?->numero, 'meta_por_minuto' => $porMinuto, 'segundos' => 0, 'meta' => null, 'pecas' => 0];
                $porOp[$chave]['segundos'] += $seg;
                if ($porMinuto !== null) {
                    $valor = $porMinuto * $seg / 60;
                    $porOp[$chave]['meta'] = ($porOp[$chave]['meta'] ?? 0) + $valor;
                    $meta = ($meta ?? 0) + $valor;
                    $passado = min($fim, $t['fim'], $agora) - max($ini, $t['inicio']);
                    $ateAgora = ($ateAgora ?? 0) + ($passado > 0 ? $porMinuto * $passado / 60 : 0);
                }
            }
            foreach ($pecasPorOp[$h['inicio']] ?? [] as $opId => $p) {
                $chave = $opId ?: 0;
                $porOp[$chave] ??= ['op_id' => $opId ?: null, 'numero' => $ops->get($opId)?->numero, 'meta_por_minuto' => null, 'segundos' => 0, 'meta' => null, 'pecas' => 0];
                $porOp[$chave]['pecas'] += $p;
            }
            foreach ($porOp as &$o) {
                $o['meta'] = $o['meta'] === null ? null : round($o['meta'], 1);
            }
            unset($o);
            $h['meta'] = $meta === null ? null : (int) round($meta);
            $h['metaAteAgora'] = $meta === null || $agora < $ini ? null : $ateAgora;
            $h['ops'] = array_values($porOp);
        }
        unset($h);

        if ($gravar) {
            $this->gravar($maquina, $horas, $de, $fuso, $agora);
        }

        return $horas;
    }

    /**
     * Trechos do turno com a OP de cada um: os apontamentos (cortados no turno e até agora), os buracos com a OP do
     * trecho anterior e, depois do último, até o fim do turno, a OP atual (futuro).
     *
     * @return list<array{inicio: int, fim: int, op: ?int, futuro: bool}>
     */
    private function trechos(Collection $aps, int $de, int $ate, int $agora, ?int $opAtual): array
    {
        $trechos = [];
        $cursor = $de;
        $primeiraOp = $aps->first(fn ($a) => $a->ordem_producao_id)?->ordem_producao_id;
        $anterior = $primeiraOp !== null ? (int) $primeiraOp : null;
        foreach ($aps as $a) {
            $ini = max($a->inicio->getTimestamp(), $de);
            $fim = min($a->fim ? $a->fim->getTimestamp() + 1 : $agora, $ate, max($agora, $ini));
            $op = $a->ordem_producao_id ? (int) $a->ordem_producao_id : $anterior;
            if ($ini > $cursor) {
                $trechos[] = ['inicio' => $cursor, 'fim' => min($ini, $agora), 'op' => $anterior, 'futuro' => false];
            }
            if ($fim > $ini) {
                $trechos[] = ['inicio' => $ini, 'fim' => $fim, 'op' => $op, 'futuro' => false];
            }
            $anterior = $op;
            $cursor = max($cursor, $fim);
        }
        // Do último apontamento até agora (sem apontamento) e de agora ao fim do turno: a OP atual da máquina.
        $depois = $opAtual ?? $anterior;
        if ($cursor < $agora && $cursor < $ate) {
            $trechos[] = ['inicio' => $cursor, 'fim' => min($agora, $ate), 'op' => $depois, 'futuro' => false];
            $cursor = min($agora, $ate);
        }
        if ($cursor < $ate) {
            $trechos[] = ['inicio' => max($cursor, $agora), 'fim' => $ate, 'op' => $depois, 'futuro' => true];
        }

        return array_values(array_filter($trechos, fn ($t) => $t['fim'] > $t['inicio']));
    }

    /** Grava meta e peças das horas que já começaram, por OP (só o que mudou; some a OP que saiu da hora). */
    private function gravar(Maquina $maquina, array $horas, CarbonImmutable $de, string $fuso, int $agora): void
    {
        $comecadas = array_filter($horas, fn ($h) => CarbonImmutable::parse($h['inicio'])->getTimestamp() <= $agora);
        if (! $comecadas) {
            return;
        }
        $inicios = array_map(fn ($h) => CarbonImmutable::parse($h['inicio'])->utc(), $comecadas);
        $existentes = MetaHora::where('maquina_id', (int) $maquina->id)
            ->whereBetween('hora_inicio', [min($inicios), max($inicios)])->get()
            ->keyBy(fn ($r) => $r->hora_inicio->getTimestamp().'|'.(int) $r->ordem_producao_id);

        $vistos = [];
        foreach ($comecadas as $h) {
            $hora = CarbonImmutable::parse($h['inicio'])->utc();
            foreach ($h['ops'] as $o) {
                if (! $o['op_id']) {
                    continue; // sem OP não tem meta para guardar
                }
                $chave = $hora->getTimestamp().'|'.(int) $o['op_id'];
                $vistos[$chave] = true;
                $novo = ['meta_por_minuto' => $o['meta_por_minuto'], 'segundos' => (int) $o['segundos'],
                    'meta' => $o['meta'], 'pecas' => (int) $o['pecas']];
                $r = $existentes->get($chave);
                if (! $r) {
                    MetaHora::create(['maquina_id' => (int) $maquina->id, 'ordem_producao_id' => (int) $o['op_id'],
                        'hora_inicio' => $hora, 'dia' => $hora->setTimezone($fuso)->format('Y-m-d'), 'atualizado_em' => now()] + $novo);

                    continue;
                }
                $mudou = (int) $r->segundos !== $novo['segundos'] || (int) $r->pecas !== $novo['pecas']
                    || abs((float) $r->meta - (float) $novo['meta']) > 0.05 || ($r->meta === null) !== ($novo['meta'] === null)
                    || abs((float) $r->meta_por_minuto - (float) $novo['meta_por_minuto']) > 0.001;
                if ($mudou) {
                    $r->forceFill($novo + ['atualizado_em' => now()])->save();
                }
            }
        }
        // A OP saiu daquela hora (apontamento corrigido): a linha dela deixa de valer.
        foreach ($existentes as $chave => $r) {
            if (! isset($vistos[$chave])) {
                $r->delete();
            }
        }
    }
}
