<?php

namespace Tests\Unit;

use App\Services\CalculadoraOee;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CalculadoraOeeTest extends TestCase
{
    public function test_exemplo_classico(): void
    {
        // 480 min planejados, 47 de parada, ciclo ideal 1 s, 19.271 peças, 18.848 boas
        $r = (new CalculadoraOee)->calcular(480, 47, 1.0, 19271, 18848);

        $this->assertEqualsWithDelta(0.9021, $r->disponibilidade, 1e-4);
        $this->assertEqualsWithDelta(0.7418, $r->performance, 1e-4);
        $this->assertEqualsWithDelta(0.9780, $r->qualidade, 1e-4);
        $this->assertEqualsWithDelta(0.6544, $r->oee(), 1e-4);
    }

    public function test_parada_total_zera_oee(): void
    {
        $this->assertSame(0.0, (new CalculadoraOee)->calcular(60, 60, 1, 0, 0)->oee());
    }

    public function test_performance_limitada_a_100(): void
    {
        $this->assertSame(1.0, (new CalculadoraOee)->calcular(60, 0, 10, 1000, 1000)->performance);
    }

    public static function entradasInvalidas(): array
    {
        return [
            'sem tempo planejado' => [0, 0, 1, 1, 1],
            'paradas maiores que o planejado' => [60, 70, 1, 1, 1],
            'ciclo zero' => [60, 0, 0, 1, 1],
            'mais boas que produzidas' => [60, 0, 1, 10, 11],
        ];
    }

    #[DataProvider('entradasInvalidas')]
    public function test_entradas_invalidas(float $plan, float $par, float $ciclo, int $total, int $boas): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CalculadoraOee)->calcular($plan, $par, $ciclo, $total, $boas);
    }
}
