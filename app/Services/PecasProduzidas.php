<?php

namespace App\Services;

use App\Models\ApontamentoEstado;
use App\Models\Legenda;
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
        $produzindo = Legenda::withoutGlobalScopes()->whereKey($ap->legenda_id)->value('tipo') === 'produzindo';
        if ($ap->ciclos === null || ! $produzindo) {
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
    public function porHora(Maquina $maquina, CarbonImmutable $de, CarbonImmutable $ate, string $fuso): array
    {
        $agora = CarbonImmutable::now();
        $horas = [];
        // (o período termina 1 min depois do fim do turno: a hora do fim, sem tempo de turno, não vira barra)
        for ($h = $de->setTimezone($fuso)->startOfHour(); $h->lessThan($ate->subMinutes(2)); $h = $h->addHour()) {
            $horas[] = ['inicio' => $h, 'fim' => $h->addHour(), 'pecas' => 0.0];
        }
        $apontamentos = ApontamentoEstado::where('maquina_id', $maquina->id)->whereNotNull('pecas')->where('pecas', '>', 0)
            ->tocando($de, $ate)->get(['inicio', 'fim', 'pecas']);
        foreach ($apontamentos as $a) {
            $ini = $a->inicio->getTimestamp();
            $fim = ($a->fim ?? $agora)->getTimestamp() + ($a->fim ? 1 : 0);
            $duracao = max(1, $fim - $ini);
            foreach ($horas as &$h) {
                $sobreposto = min($fim, $h['fim']->getTimestamp()) - max($ini, $h['inicio']->getTimestamp());
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
