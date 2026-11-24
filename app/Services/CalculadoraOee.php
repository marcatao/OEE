<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * OEE (Overall Equipment Effectiveness) = Disponibilidade × Performance × Qualidade.
 */
class CalculadoraOee
{
    public function calcular(
        float $tempoPlanejadoMin,
        float $paradasMin,
        float $cicloIdealS,
        int $totalProduzido,
        int $pecasBoas,
    ): ResultadoOee {
        if ($tempoPlanejadoMin <= 0) {
            throw new InvalidArgumentException('O tempo planejado deve ser maior que zero.');
        }
        if ($paradasMin < 0 || $paradasMin > $tempoPlanejadoMin) {
            throw new InvalidArgumentException('As paradas devem estar entre zero e o tempo planejado.');
        }
        if ($cicloIdealS <= 0) {
            throw new InvalidArgumentException('O ciclo ideal deve ser maior que zero.');
        }
        if ($totalProduzido < 0 || $pecasBoas < 0 || $pecasBoas > $totalProduzido) {
            throw new InvalidArgumentException('As peças boas devem estar entre zero e o total produzido.');
        }

        $tempoOperandoMin = $tempoPlanejadoMin - $paradasMin;
        $disponibilidade = $tempoOperandoMin / $tempoPlanejadoMin;
        $performance = $tempoOperandoMin > 0
            ? min(($cicloIdealS * $totalProduzido) / ($tempoOperandoMin * 60), 1.0)
            : 0.0;
        $qualidade = $totalProduzido > 0 ? $pecasBoas / $totalProduzido : 0.0;

        return new ResultadoOee($disponibilidade, $performance, $qualidade);
    }

    /**
     * Peças que a meta pede no tempo produzindo: meta (pç/min, item MO da estrutura da OP) × minutos produzindo.
     */
    public static function pecasNaMeta(float $metaPorMinuto, float $segundosProduzindo): float
    {
        return $metaPorMinuto > 0 && $segundosProduzindo > 0 ? $metaPorMinuto * $segundosProduzindo / 60 : 0.0;
    }

    /**
     * Performance pelo IoT = peças reais ÷ peças na meta (meta × tempo produzindo). Pode passar de 100% (meta baixa
     * ou ciclo acima do padrão): o valor mostrado é o real; no OEE entra limitado a 100%. null sem meta no período.
     */
    public static function performancePelaMeta(float $pecas, float $pecasNaMeta): ?float
    {
        return $pecasNaMeta > 0 ? max(0.0, $pecas) / $pecasNaMeta : null;
    }

    /**
     * Qualidade pelo IoT e as perdas apontadas (24/11/2026): o contador do IoT conta TODA peça, inclusive as perdidas,
     * então peças boas = peças do IoT − perdas, e qualidade = boas ÷ peças do IoT. Perda maior que a contagem = 0%.
     * null sem peças contadas no período.
     */
    public static function qualidadePelasPerdas(float $pecas, float $perdas): ?float
    {
        if ($pecas <= 0) {
            return null;
        }

        return max(0.0, $pecas - max(0.0, $perdas)) / $pecas;
    }

    /**
     * OEE pelos apontamentos reais e o IoT = disponibilidade × performance (limitada a 100%) × qualidade. Sem qualidade
     * (nenhuma peça contada) a qualidade não entra (vale 100%).
     */
    public static function oeeIot(?float $disponibilidade, ?float $performance, ?float $qualidade = null): ?float
    {
        return $disponibilidade !== null && $performance !== null
            ? $disponibilidade * min($performance, 1.0) * ($qualidade ?? 1.0) : null;
    }
}
