<?php

namespace Tests\Unit;

use App\Services\IndicadoresOee;
use App\Services\IndicadoresPeriodo;
use PHPUnit\Framework\TestCase;

class IntervalosTest extends TestCase
{
    public function test_une_intervalos_sobrepostos_e_encostados(): void
    {
        $this->assertSame([[0, 20], [30, 40]], IndicadoresOee::unir([[30, 40], [0, 10], [5, 15], [15, 20], [9, 9]]));
    }

    public function test_intersecao_corta_nas_janelas(): void
    {
        $this->assertSame([[5, 10], [20, 25]], IndicadoresOee::intersecao([[5, 25]], [[0, 10], [20, 30]]));
        $this->assertSame(10, IndicadoresOee::duracao([[5, 10], [20, 25]]));
    }

    public function test_sem_base_de_calculo_e_sem_dados_e_nao_zero(): void
    {
        $vazio = IndicadoresPeriodo::vazio();
        $this->assertNull($vazio->oee());
        $this->assertNull($vazio->disponibilidade());
        $this->assertFalse($vazio->temDados());
    }

    public function test_somar_junta_tempos_e_pecas_sem_media_de_porcentagens(): void
    {
        // Máquina A: 100 min planejados, operando 100, ideal 100 → 100%. Máquina B: 300 min, operando 150, ideal 75.
        $a = new IndicadoresPeriodo(100, 0, 0, 100, 100, 100, 1);
        $b = new IndicadoresPeriodo(300, 0, 150, 75, 100, 50, 1);
        $total = IndicadoresPeriodo::somar([$a, $b]);

        $this->assertEqualsWithDelta(250 / 400, $total->disponibilidade(), 1e-9);   // não (100% + 50%) / 2
        $this->assertEqualsWithDelta(175 / 250, $total->performance(), 1e-9);
        $this->assertEqualsWithDelta(150 / 200, $total->qualidade(), 1e-9);
        $this->assertSame(2, $total->apontamentos);
    }
}
