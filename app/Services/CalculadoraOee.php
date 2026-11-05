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
}
