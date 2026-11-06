<?php

namespace App\Services;

/**
 * Tempos, peças e índices de OEE de uma máquina (ou de várias somadas) num período.
 * Índices sem base de cálculo ficam null ("sem dados"), nunca 0%.
 */
final readonly class IndicadoresPeriodo
{
    /**
     * @param  array<int, array{motivo: string, planejada: bool, minutos: float, ocorrencias: int}>  $pareto
     */
    public function __construct(
        public float $tempoProgramadoMin,
        public float $paradasPlanejadasMin,
        public float $paradasNaoPlanejadasMin,
        public float $producaoIdealMin, // soma de ciclo ideal × produzido, em minutos
        public int $totalProduzido,
        public int $pecasBoas,
        public int $apontamentos,
        public array $pareto = [],
    ) {}

    public static function vazio(): self
    {
        return new self(0, 0, 0, 0, 0, 0, 0);
    }

    /** Tempo planejado de produção = programado − paradas planejadas. */
    public function tempoPlanejadoMin(): float
    {
        return max($this->tempoProgramadoMin - $this->paradasPlanejadasMin, 0.0);
    }

    /** Tempo operando = planejado − paradas não planejadas. */
    public function tempoOperandoMin(): float
    {
        return max($this->tempoPlanejadoMin() - $this->paradasNaoPlanejadasMin, 0.0);
    }

    public function refugo(): int
    {
        return $this->totalProduzido - $this->pecasBoas;
    }

    public function disponibilidade(): ?float
    {
        $planejado = $this->tempoPlanejadoMin();

        return $planejado > 0 ? $this->tempoOperandoMin() / $planejado : null;
    }

    /** Limitada a 100%; acima disso o ciclo ideal do cadastro está errado (ver performanceAcimaDoIdeal()). */
    public function performance(): ?float
    {
        $operando = $this->tempoOperandoMin();
        if ($operando <= 0 || $this->producaoIdealMin <= 0) {
            return null;
        }

        return min($this->producaoIdealMin / $operando, 1.0);
    }

    public function performanceAcimaDoIdeal(): bool
    {
        $operando = $this->tempoOperandoMin();

        return $operando > 0 && $this->producaoIdealMin / $operando > 1.0001;
    }

    public function qualidade(): ?float
    {
        return $this->totalProduzido > 0 ? $this->pecasBoas / $this->totalProduzido : null;
    }

    public function oee(): ?float
    {
        $d = $this->disponibilidade();
        $p = $this->performance();
        $q = $this->qualidade();

        return $d === null || $p === null || $q === null ? null : $d * $p * $q;
    }

    public function temDados(): bool
    {
        return $this->apontamentos > 0;
    }

    /**
     * Junta máquinas/períodos somando tempos e peças (nunca média de porcentagens).
     *
     * @param  iterable<self>  $itens
     */
    public static function somar(iterable $itens): self
    {
        $t = [0.0, 0.0, 0.0, 0.0, 0, 0, 0];
        $pareto = [];
        foreach ($itens as $i) {
            $t[0] += $i->tempoProgramadoMin;
            $t[1] += $i->paradasPlanejadasMin;
            $t[2] += $i->paradasNaoPlanejadasMin;
            $t[3] += $i->producaoIdealMin;
            $t[4] += $i->totalProduzido;
            $t[5] += $i->pecasBoas;
            $t[6] += $i->apontamentos;
            foreach ($i->pareto as $linha) {
                $chave = $linha['motivo'];
                $pareto[$chave] ??= ['motivo' => $chave, 'planejada' => $linha['planejada'], 'minutos' => 0.0, 'ocorrencias' => 0];
                $pareto[$chave]['minutos'] += $linha['minutos'];
                $pareto[$chave]['ocorrencias'] += $linha['ocorrencias'];
            }
        }
        usort($pareto, fn ($a, $b) => $b['minutos'] <=> $a['minutos']);

        return new self(...$t, pareto: array_values($pareto));
    }
}
