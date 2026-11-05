<?php

namespace App\Services;

final readonly class ResultadoOee
{
    public function __construct(
        public float $disponibilidade,
        public float $performance,
        public float $qualidade,
    ) {}

    public function oee(): float
    {
        return $this->disponibilidade * $this->performance * $this->qualidade;
    }
}
