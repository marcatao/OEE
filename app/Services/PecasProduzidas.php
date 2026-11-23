<?php

namespace App\Services;

use App\Models\ApontamentoEstado;
use App\Models\Maquina;
use App\Models\OrdemProducao;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Peças produzidas pelo IoT (pedido do usuário em 22/11/2026).
 *
 * - O Integrador MQTT conta os CICLOS (pulsos do contador do canal de produção) de cada apontamento de produção
 *   (legenda do tipo "produzindo") e grava em apontamentos_estado.ciclos.
 * - Peças do apontamento = ciclos × cavidades ativas do molde da OP naquela máquina (roteiro SG2 → ferramenta SH4,
 *   h4_qtdcavativas); sem molde (ou sem OP) = 1 peça por ciclo. As cavidades usadas ficam gravadas no apontamento
 *   (congeladas na 1ª conta; trocar a OP do apontamento recalcula).
 * - ordens_producao.quantidade_real = soma das peças dos apontamentos de produção da OP.
 * - Peças por minuto (Timeline e app) = 60 ÷ ciclo médio × cavidades, com a máquina produzindo.
 */
class PecasProduzidas
{
    public function __construct(private EngenhariaOrdemProducao $engenharia) {}

    /** Cavidades ativas do molde da OP na máquina (1 sem OP, sem molde ou sem cavidades). */
    public function cavidades(?OrdemProducao $op, ?Maquina $maquina): int
    {
        if (! $op || ! $maquina) {
            return 1;
        }
        $roteiro = $this->engenharia->roteiro($op, comMaquinas: false, todasUnidades: true);

        return self::cavidadesDosMoldes(EngenhariaOrdemProducao::moldesDaMaquina($roteiro, (string) $maquina->codigo));
    }

    /** Peças por minuto agora: 60 ÷ ciclo médio (s por ciclo) × cavidades; 0 sem ciclo médio. */
    public static function porMinuto(?int $cicloMedio, int $cavidades): float
    {
        return $cicloMedio !== null && $cicloMedio > 0 ? round(60 / $cicloMedio * max(1, $cavidades), 1) : 0.0;
    }

    /**
     * Calcula e grava as peças do apontamento (sem disparar eventos). Devolve o id da OP do apontamento (para somar a
     * quantidade real) ou null.
     */
    public function aplicar(ApontamentoEstado $ap): ?int
    {
        // Toda peça produzida conta, em qualquer legenda (ciclo mais lento que a microparada cai num 99, e a troca do
        // operador para "Setup" não apaga o que a máquina fez); sem ciclos (apontamento manual), sem peças.
        if ($ap->ciclos === null) {
            $novo = ['pecas' => null];
        } else {
            $cavidades = $ap->cavidades ?? $this->cavidades(
                $ap->ordem_producao_id ? OrdemProducao::find($ap->ordem_producao_id) : null,
                Maquina::withoutGlobalScopes()->find($ap->maquina_id));
            $novo = ['cavidades' => $cavidades, 'pecas' => (int) $ap->ciclos * $cavidades];
        }
        $ap->forceFill($novo);
        if ($ap->isDirty()) {
            $ap->saveQuietly();
        }

        return $ap->ordem_producao_id ? (int) $ap->ordem_producao_id : null;
    }

    /**
     * Conferência periódica (agendador, a cada 5 min): apontamentos dos últimos dias com ciclos e peças desatualizadas
     * (aviso do integrador perdido com o portal fora do ar e o integrador reiniciado). Refaz as peças e as OPs.
     *
     * @return array{apontamentos: int, ops: int}
     */
    public function conferir(int $dias = 3): array
    {
        $ops = [];
        $n = 0;
        ApontamentoEstado::whereNotNull('ciclos')->where('inicio', '>=', now()->subDays($dias))
            ->where(fn ($q) => $q->whereNull('pecas')->orWhereNull('cavidades')->orWhereRaw('pecas <> ciclos * cavidades'))
            ->orderBy('id')->limit(2000)->get()
            ->each(function (ApontamentoEstado $ap) use (&$ops, &$n) {
                $ops[] = $this->aplicar($ap);
                $n++;
            });
        $ops = array_values(array_unique(array_filter($ops)));
        foreach ($ops as $op) {
            $this->recalcularOp($op);
        }

        return ['apontamentos' => $n, 'ops' => count($ops)];
    }

    /**
     * "Contagem IoT" da OP (card da tela da OP e do app): as MESMAS peças da Produção por hora (apontamentos.pecas =
     * ciclos × cavidades), somadas na hora: total, ciclos, hoje (repartido pelo tempo, como o gráfico), por máquina e a
     * última contagem. Se ordens_producao.quantidade_real estiver diferente (aviso perdido), já grava o total certo.
     *
     * @return array{total: int, ciclos: int, hoje: int, apontamentos: int, ultima: ?CarbonImmutable, por_maquina: list<array{maquina: string, pecas: int}>}
     */
    public function contagemDaOp(OrdemProducao $op, string $fuso): array
    {
        $aps = ApontamentoEstado::where('ordem_producao_id', $op->id)->whereNotNull('pecas')
            ->get(['maquina_id', 'inicio', 'fim', 'pecas', 'ciclos', 'atualizado_em']);
        $total = (int) $aps->sum('pecas');
        if (abs($total - (float) $op->quantidade_real) > 0.0001) {
            $this->recalcularOp((int) $op->id);
            $op->quantidade_real = $total;
        }
        $agora = CarbonImmutable::now();
        $inicioHoje = $agora->setTimezone($fuso)->startOfDay();
        $hoje = 0.0;
        foreach ($aps as $a) {
            if ($a->pecas <= 0) {
                continue;
            }
            $ini = $a->inicio->getTimestamp();
            $ultima = $a->atualizado_em && $a->atualizado_em->greaterThan($a->inicio) && $a->atualizado_em->lessThan($agora) ? $a->atualizado_em : $agora;
            $fim = ($a->fim ?? $ultima)->getTimestamp() + ($a->fim ? 1 : 0);
            $sobreposto = min($fim, $agora->getTimestamp()) - max($ini, $inicioHoje->getTimestamp());
            if ($sobreposto > 0) {
                $hoje += $a->pecas * $sobreposto / max(1, $fim - $ini);
            }
        }
        $nomes = Maquina::withoutGlobalScopes()->whereIntegerInRaw('id', $aps->pluck('maquina_id')->map(fn ($i) => (int) $i)->unique()->values()->all())
            ->get(['id', 'codigo', 'nome'])->keyBy(fn ($m) => (int) $m->id);
        $porMaquina = $aps->groupBy(fn ($a) => (int) $a->maquina_id)
            ->map(fn ($g, $id) => ['maquina' => trim(($nomes[$id]->codigo ?? '').' '.($nomes[$id]->nome ?? '')) ?: "máquina {$id}", 'pecas' => (int) $g->sum('pecas')])
            ->sortByDesc('pecas')->values()->all();
        $ultima = $aps->max('atualizado_em');

        return ['total' => $total, 'ciclos' => (int) $aps->sum('ciclos'), 'hoje' => (int) round($hoje),
            'apontamentos' => $aps->count(), 'ultima' => $ultima ? CarbonImmutable::instance($ultima) : null, 'por_maquina' => $porMaquina];
    }

    /** Soma as peças dos apontamentos da OP em ordens_producao.quantidade_real. */
    public function recalcularOp(?int $opId): void
    {
        if (! $opId) {
            return;
        }
        $total = (float) ApontamentoEstado::where('ordem_producao_id', $opId)->whereNotNull('pecas')->sum('pecas');
        DB::table('ordens_producao')->where('id', $opId)->update(['quantidade_real' => $total]);
    }

    /**
     * Peças por hora no período (gráfico do app): as peças de cada apontamento de produção são repartidas
     * proporcionalmente ao tempo dele em cada hora (o em aberto conta até agora).
     *
     * @return list<array{hora: string, inicio: string, pecas: int}> uma linha por hora do período, no fuso local
     */
    public function porHora(Maquina $maquina, CarbonImmutable $de, CarbonImmutable $ate, string $fuso, ?iterable $jaLidos = null): array
    {
        $agora = CarbonImmutable::now();
        $horas = [];
        // (o período termina 1 min depois do fim do turno: a hora do fim, sem tempo de turno, não vira barra)
        for ($h = $de->setTimezone($fuso)->startOfHour(); $h->lessThan($ate->subMinutes(2)); $h = $h->addHour()) {
            $horas[] = ['inicio' => $h, 'fim' => $h->addHour(), 'pecas' => 0.0];
        }
        $apontamentos = $jaLidos !== null ? collect($jaLidos)->filter(fn ($a) => $a->pecas > 0)
            : ApontamentoEstado::where('maquina_id', $maquina->id)->whereNotNull('pecas')->where('pecas', '>', 0)
                ->tocando($de, $ate)->get(['inicio', 'fim', 'pecas', 'atualizado_em']);
        $inicioTurno = $de->getTimestamp();
        foreach ($apontamentos as $a) {
            $ini = $a->inicio->getTimestamp();
            // Em aberto: as peças são as da última conta do integrador (atualizado_em), não até agora.
            $ultimaConta = $a->atualizado_em && $a->atualizado_em->greaterThan($a->inicio) ? $a->atualizado_em : $agora;
            $fim = ($a->fim ?? ($ultimaConta->lessThan($agora) ? $ultimaConta : $agora))->getTimestamp() + ($a->fim ? 1 : 0);
            $duracao = max(1, $fim - $ini);
            foreach ($horas as &$h) {
                // a 1ª hora começa no início do turno (turno às 05:30 não conta 05:00–05:30)
                $sobreposto = min($fim, $h['fim']->getTimestamp()) - max($ini, $h['inicio']->getTimestamp(), $inicioTurno);
                if ($sobreposto > 0) {
                    $h['pecas'] += $a->pecas * $sobreposto / $duracao;
                }
            }
            unset($h);
        }

        return array_map(fn ($h) => ['hora' => $h['inicio']->format('H:i'), 'inicio' => $h['inicio']->utc()->toIso8601String(),
            'pecas' => (int) round($h['pecas'])], $horas);
    }

    /** Usado pela Timeline/app: cavidades da OP atual da máquina, sem consultar o roteiro de novo se já veio. */
    public static function cavidadesDosMoldes(array $moldes): int
    {
        foreach ($moldes as $molde) {
            $ativas = (int) ($molde['ferramenta']?->h4_qtdcavativas ?? 0);
            if ($ativas > 0) {
                return $ativas;
            }
        }

        return 1;
    }
}
